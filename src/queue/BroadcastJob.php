<?php

namespace justinholtweb\pwa\queue;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\pwa\models\Campaign;
use justinholtweb\pwa\Plugin;
use yii\queue\RetryableJobInterface;

/**
 * Sends one batch of a broadcast, then queues the next.
 *
 * Chaining jobs rather than looping inside one is what keeps a broadcast to a hundred thousand
 * devices survivable. Each job is small enough to finish inside any queue timeout, its progress is
 * visible in Craft's queue rather than in a log file, and a job that dies takes one batch with it
 * instead of the whole send.
 */
class BroadcastJob extends BaseJob implements RetryableJobInterface
{
    /** Seconds one push may take before Guzzle gives up on it — see Push::send(). */
    private const PUSH_TIMEOUT = 10;

    public int $campaignId;

    /** The last subscriber ID the previous batch walked; this batch starts after it. */
    public int $afterId = 0;

    /** Rows walked by earlier batches, for the progress bar. */
    public int $walked = 0;

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $campaign = $plugin->campaigns->getCampaignById($this->campaignId);

        if ($campaign === null || $campaign->status !== Campaign::STATUS_SENDING) {
            // Cancelled, deleted, or already finished by another worker. Not an error.
            return;
        }

        $batch = $this->batchSize();
        $this->setProgress(
            $queue,
            $campaign->targeted > 0 ? min(1, $this->walked / max(1, $plugin->push->countSubscribers($campaign->siteId))) : 1,
            Craft::t('pwa', '{done} of {total}', ['done' => $this->walked, 'total' => $campaign->targeted]),
        );

        $result = $plugin->campaigns->deliverBatch($campaign, $this->afterId, $batch);

        // A short page is the end of the list. Not "sent fewer than a batch" — with topics, a full
        // page can match nobody, and stopping there would leave everyone after it unsent.
        if ($result['walked'] < $batch) {
            $plugin->campaigns->finish($campaign);
            return;
        }

        Craft::$app->getQueue()->push(new self([
            'campaignId' => $this->campaignId,
            'afterId' => $result['lastId'],
            'walked' => $this->walked + $result['walked'],
        ]));
    }

    /**
     * Long enough for every push in the batch to time out, plus room for the bookkeeping.
     *
     * The queue's default is five minutes, and a hundred devices on a push service that has
     * stopped answering is a thousand seconds — the job would be killed mid-batch and retried.
     */
    public function getTtr(): int
    {
        return $this->batchSize() * self::PUSH_TIMEOUT + 120;
    }

    /**
     * Never retried. A retry resends the whole batch, and the devices that already received it
     * would get the notification twice; a notification cannot be recalled.
     */
    public function canRetry($attempt, $error): bool
    {
        return false;
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('pwa', 'Broadcasting a notification');
    }

    private function batchSize(): int
    {
        return max(1, Plugin::getInstance()->getSettings()->pushBatchSize);
    }
}
