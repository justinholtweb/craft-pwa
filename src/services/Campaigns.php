<?php

namespace justinholtweb\pwa\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use DateTime;
use justinholtweb\pwa\models\Campaign;
use justinholtweb\pwa\Plugin;
use justinholtweb\pwa\queue\BroadcastJob;
use justinholtweb\pwa\records\CampaignRecord;
use justinholtweb\pwa\records\DeliveryRecord;
use yii\db\Expression;

/**
 * Broadcasts, from draft to delivery report.
 *
 * Sending is done in batches through the queue rather than in the request that pressed the button.
 * A thousand subscribers is a thousand HTTPS round trips to four different push services, and the
 * ways that goes wrong in a web request — timeout at 30 seconds, half the list notified, no record
 * of which half — are exactly the ways that leave somebody unable to answer "did it send?".
 *
 * So the request queues, the queue sends, and every attempt is written down. The counts on the
 * campaign are the sum of what happened, not an estimate made in advance.
 */
class Campaigns extends Component
{
    public function getCampaignById(int $id): ?Campaign
    {
        $record = CampaignRecord::findOne($id);

        return $record ? $this->toModel($record) : null;
    }

    /**
     * @return Campaign[]
     */
    public function getCampaigns(?string $status = null, int $limit = 100): array
    {
        $query = CampaignRecord::find()->orderBy(['dateCreated' => SORT_DESC])->limit($limit);

        if ($status !== null) {
            $query->andWhere(['status' => $status]);
        }

        return array_map(fn(CampaignRecord $r) => $this->toModel($r), $query->all());
    }

    /**
     * The campaign raised from an entry, if there is one.
     *
     * Used to keep publish-triggered broadcasts to one per entry: an entry is saved many times
     * after it goes live, and every one of those saves is a chance to notify everybody twice.
     */
    public function getCampaignByEntryId(int $entryId): ?Campaign
    {
        $record = CampaignRecord::find()->where(['entryId' => $entryId])->one();

        return $record ? $this->toModel($record) : null;
    }

    public function save(Campaign $campaign): bool
    {
        if (!$campaign->validate()) {
            return false;
        }

        $record = $campaign->id ? CampaignRecord::findOne($campaign->id) : new CampaignRecord();

        if ($record === null) {
            return false;
        }

        $record->siteId = $campaign->siteId;
        $record->title = $campaign->title;
        $record->body = $campaign->body;
        $record->url = $campaign->url;
        $record->icon = $campaign->icon;
        $record->badge = $campaign->badge;
        $record->tag = $campaign->tag;
        $record->requireInteraction = $campaign->requireInteraction;
        $record->topics = Json::encode(array_values($campaign->topics));
        $record->entryId = $campaign->entryId;
        $record->status = $campaign->status;
        $record->dateScheduled = $campaign->dateScheduled ? Db::prepareDateForDb($campaign->dateScheduled) : null;
        $record->dateSent = $campaign->dateSent ? Db::prepareDateForDb($campaign->dateSent) : null;
        $record->targeted = $campaign->targeted;
        $record->delivered = $campaign->delivered;
        $record->failed = $campaign->failed;
        $record->createdBy = $campaign->createdBy ?? Craft::$app->getUser()->getId();

        if (!$record->save()) {
            Plugin::error('Could not save a campaign: ' . Json::encode($record->getErrors()));
            return false;
        }

        $campaign->id = (int)$record->id;
        $campaign->uid = (string)$record->uid;

        return true;
    }

    /** Deletes a campaign — but not one whose batches are still on the queue. */
    public function delete(int $id): bool
    {
        if ($this->getCampaignById($id)?->status === Campaign::STATUS_SENDING) {
            return false;
        }

        return CampaignRecord::deleteAll(['id' => $id]) > 0;
    }

    /**
     * Puts a campaign on the queue.
     *
     * The subscriber count is taken here, once, and stored — so the report says how many devices
     * the broadcast was aimed at, not how many exist now. Somebody unsubscribing during a send
     * should not make the arithmetic on a finished report stop adding up.
     */
    public function broadcast(Campaign $campaign): bool
    {
        $push = Plugin::getInstance()->push;

        $campaign->targeted = $push->countMatching($campaign->siteId, $campaign->topics);
        $campaign->delivered = 0;
        $campaign->failed = 0;
        $campaign->status = Campaign::STATUS_SENDING;
        $campaign->dateSent = new DateTime();

        if (!$this->save($campaign)) {
            return false;
        }

        DeliveryRecord::deleteAll(['campaignId' => $campaign->id]);

        if ($campaign->targeted === 0) {
            $campaign->status = Campaign::STATUS_SENT;
            $this->save($campaign);

            Plugin::info("Campaign {$campaign->id} had no subscribers to send to.");

            return true;
        }

        Craft::$app->getQueue()->push(new BroadcastJob([
            'campaignId' => $campaign->id,
        ]));

        Plugin::info("Campaign {$campaign->id} queued for {$campaign->targeted} device(s).");

        return true;
    }

    /**
     * Sends one batch, starting after a subscriber ID.
     *
     * Returns how many rows were *walked* (which is how the job knows whether to continue — a
     * short page is the end of the list), how many devices were sent to (fewer, when topics
     * filtered some out), and the last ID walked, which is where the next batch starts. Keyset
     * rather than offset, because sending deletes devices that are gone, and an offset into a
     * list that shrinks underneath it skips whoever slid back into the gap.
     *
     * @return array{walked: int, sent: int, lastId: int}
     */
    public function deliverBatch(Campaign $campaign, int $afterId, int $limit): array
    {
        $push = Plugin::getInstance()->push;
        $page = $push->subscriberPage($campaign->siteId, $campaign->topics, $afterId, $limit);
        $subscribers = $page['subscribers'];

        if (empty($subscribers)) {
            return ['walked' => $page['walked'], 'sent' => 0, 'lastId' => $page['lastId']];
        }

        $delivered = 0;
        $failed = 0;
        $rows = [];
        $now = Db::prepareDateForDb(new DateTime());

        foreach ($subscribers as $subscriber) {
            [$status, $code, $error] = $push->send($subscriber, $campaign);

            if ($status === 'delivered') {
                $delivered++;
            } else {
                $failed++;
            }

            $rows[] = [
                $campaign->id,
                $subscriber->id,
                $status,
                $code,
                $error !== null ? substr($error, 0, 1000) : null,
                $now,
                $now,
                StringHelper::UUID(),
            ];
        }

        // Sending drops devices the push service says are gone, so by now some of the IDs
        // collected above no longer exist — and a delivery row pointing at a deleted subscriber
        // fails the foreign key and takes the *whole* batch insert with it, losing the record of
        // every send in it. The surviving IDs are looked up once and the rest are recorded as
        // deliveries with no device, which is exactly what they are.
        $ids = array_values(array_filter(array_map(static fn(array $row) => $row[1], $rows)));

        $surviving = $ids === [] ? [] : array_flip(array_map('intval', (new Query())
            ->select(['id'])
            ->from('{{%pwa_subscribers}}')
            ->where(['id' => $ids])
            ->column()));

        foreach ($rows as $index => $row) {
            if ($row[1] !== null && !isset($surviving[(int)$row[1]])) {
                $rows[$index][1] = null;
            }
        }

        Craft::$app->getDb()->createCommand()->batchInsert(
            '{{%pwa_deliveries}}',
            ['campaignId', 'subscriberId', 'status', 'statusCode', 'error', 'dateCreated', 'dateUpdated', 'uid'],
            $rows,
        )->execute();

        // Incremented in SQL rather than read-modify-written: two batches running concurrently on
        // a queue with more than one worker would otherwise each overwrite the other's total.
        Craft::$app->getDb()->createCommand()->update(
            '{{%pwa_campaigns}}',
            [
                'delivered' => new Expression('[[delivered]] + :d', [':d' => $delivered]),
                'failed' => new Expression('[[failed]] + :f', [':f' => $failed]),
            ],
            ['id' => $campaign->id],
        )->execute();

        return ['walked' => $page['walked'], 'sent' => count($subscribers), 'lastId' => $page['lastId']];
    }

    /** Marks a campaign finished, and says how it went. */
    public function finish(Campaign $campaign): void
    {
        $fresh = $this->getCampaignById((int)$campaign->id);

        if ($fresh === null) {
            return;
        }

        $fresh->status = $fresh->delivered === 0 && $fresh->failed > 0
            ? Campaign::STATUS_FAILED
            : Campaign::STATUS_SENT;

        $this->save($fresh);

        Plugin::info("Campaign {$fresh->id} finished: {$fresh->delivered} delivered, {$fresh->failed} failed.");
    }

    /**
     * Scheduled campaigns whose time has come.
     *
     * @return Campaign[]
     */
    public function getDue(): array
    {
        $records = CampaignRecord::find()
            ->where(['status' => Campaign::STATUS_SCHEDULED])
            ->andWhere(['<=', 'dateScheduled', Db::prepareDateForDb(new DateTime())])
            ->all();

        return array_map(fn(CampaignRecord $r) => $this->toModel($r), $records);
    }

    /**
     * Raises a campaign from an entry.
     *
     * The title is the entry's, the URL is the entry's, and the body is left to whoever writes it
     * — a notification whose body is the first sentence of the article is a notification that says
     * nothing twice.
     */
    public function fromEntry(Entry $entry): Campaign
    {
        $campaign = new Campaign();
        $campaign->siteId = $entry->siteId;
        $campaign->title = (string)$entry->title;
        $campaign->url = (string)($entry->getUrl() ?? UrlHelper::siteUrl('/', null, null, $entry->siteId));
        $campaign->entryId = (int)$entry->id;

        return $campaign;
    }

    /** Drops delivery rows past the retention window. Called from garbage collection. */
    public function pruneDeliveries(): int
    {
        $days = Plugin::getInstance()->getSettings()->deliveryRetentionDays;

        if ($days <= 0) {
            return 0;
        }

        $cutoff = (new DateTime())->modify("-{$days} days");

        return DeliveryRecord::deleteAll(['<', 'dateCreated', Db::prepareDateForDb($cutoff)]);
    }

    /**
     * A campaign's delivery breakdown, for the report.
     *
     * @return array<string, int>
     */
    public function getDeliveryBreakdown(int $campaignId): array
    {
        $rows = DeliveryRecord::find()
            ->select(['status', 'COUNT(*) AS total'])
            ->where(['campaignId' => $campaignId])
            ->groupBy(['status'])
            ->asArray()
            ->all();

        $out = [];

        foreach ($rows as $row) {
            $out[(string)$row['status']] = (int)$row['total'];
        }

        return $out;
    }

    private function toModel(CampaignRecord $record): Campaign
    {
        $topics = $record->topics;

        return new Campaign([
            'id' => (int)$record->id,
            'uid' => (string)$record->uid,
            'siteId' => $record->siteId !== null ? (int)$record->siteId : null,
            'title' => (string)$record->title,
            'body' => (string)$record->body,
            'url' => (string)$record->url,
            'icon' => (string)$record->icon,
            'badge' => (string)$record->badge,
            'tag' => (string)$record->tag,
            'requireInteraction' => (bool)$record->requireInteraction,
            'topics' => is_string($topics) ? (array)Json::decodeIfJson($topics) : (array)($topics ?? []),
            'entryId' => $record->entryId !== null ? (int)$record->entryId : null,
            'status' => (string)$record->status,
            'dateScheduled' => $record->dateScheduled ? (DateTimeHelper::toDateTime($record->dateScheduled) ?: null) : null,
            'dateSent' => $record->dateSent ? (DateTimeHelper::toDateTime($record->dateSent) ?: null) : null,
            'targeted' => (int)$record->targeted,
            'delivered' => (int)$record->delivered,
            'failed' => (int)$record->failed,
            'createdBy' => $record->createdBy !== null ? (int)$record->createdBy : null,
            'dateCreated' => $record->dateCreated ? (DateTimeHelper::toDateTime($record->dateCreated) ?: null) : null,
        ]);
    }
}
