<?php

namespace justinholtweb\pwa\queue;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\pwa\models\Campaign;
use justinholtweb\pwa\Plugin;

/**
 * Sends one batch of a broadcast, then queues the next.
 *
 * Chaining jobs rather than looping inside one is what keeps a broadcast to a hundred thousand
 * devices survivable. Each job is small enough to finish inside any queue timeout, its progress is
 * visible in Craft's queue rather than in a log file, and a job that dies takes one batch with it
 * instead of the whole send.
 */
class BroadcastJob extends BaseJob
{
    public int $campaignId;
    public int $offset = 0;

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $campaign = $plugin->campaigns->getCampaignById($this->campaignId);

        if ($campaign === null || $campaign->status !== Campaign::STATUS_SENDING) {
            // Cancelled, deleted, or already finished by another worker. Not an error.
            return;
        }

        $batch = max(1, $plugin->getSettings()->pushBatchSize);
        $this->setProgress(
            $queue,
            $campaign->targeted > 0 ? min(1, $this->offset / $campaign->targeted) : 1,
            Craft::t('pwa', '{done} of {total}', ['done' => $this->offset, 'total' => $campaign->targeted]),
        );

        $sent = $plugin->campaigns->deliverBatch($campaign, $this->offset, $batch);

        if ($sent < $batch) {
            $plugin->campaigns->finish($campaign);
            return;
        }

        // The offset does not advance by the number *sent* but by the number *walked*, because
        // devices dropped mid-send shift the list underneath us. Walking by a fixed stride can
        // skip a device; the alternative — an ever-shifting cursor — can send twice. Skipping one
        // notification is the better failure, and a subscriber dropped for being gone was never
        // going to receive it anyway.
        Craft::$app->getQueue()->push(new self([
            'campaignId' => $this->campaignId,
            'offset' => $this->offset + $sent,
        ]));
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('pwa', 'Broadcasting a notification');
    }
}
