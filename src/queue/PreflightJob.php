<?php

namespace justinholtweb\pwa\queue;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\pwa\Plugin;
use yii\queue\RetryableJobInterface;

/**
 * Runs the scheduled preflight over every site, then emails whatever it found.
 *
 * On the queue rather than in the request that noticed it was due, because a check makes a dozen
 * HTTP requests to the site — and doing that inside a control panel page load means the person who
 * happened to open the dashboard waits for it.
 */
class PreflightJob extends BaseJob implements RetryableJobInterface
{
    /** Limits the run to one site. Null checks them all. */
    public ?int $siteId = null;

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();

        $sites = $this->siteId !== null
            ? array_filter([Craft::$app->getSites()->getSiteById($this->siteId)])
            : Craft::$app->getSites()->getAllSites();

        $total = max(1, count($sites));
        $index = 0;

        foreach ($sites as $site) {
            $this->setProgress($queue, $index / $total, $site->getName());

            $audit = $plugin->preflight->run($site, 'scheduled');
            $plugin->preflight->save($audit);
            $plugin->notifications->sendPreflightReport($audit);

            $index++;
        }
    }

    /**
     * Sized for the sites it will check. One check makes about a dozen requests with a ten-second
     * timeout each, so a multi-site install blows through the queue's default five minutes on a
     * slow day — and a job killed halfway is retried from the first site.
     */
    public function getTtr(): int
    {
        $sites = $this->siteId !== null ? 1 : count(Craft::$app->getSites()->getAllSiteIds());

        return max(1, $sites) * 150 + 60;
    }

    /** Once is enough: a retry would email the same report again. */
    public function canRetry($attempt, $error): bool
    {
        return false;
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('pwa', 'Running the PWA preflight check');
    }
}
