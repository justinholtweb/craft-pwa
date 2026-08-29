<?php

namespace justinholtweb\pwa\queue;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\pwa\Plugin;

/**
 * Runs the scheduled preflight over every site, then emails whatever it found.
 *
 * On the queue rather than in the request that noticed it was due, because a check makes a dozen
 * HTTP requests to the site — and doing that inside a control panel page load means the person who
 * happened to open the dashboard waits for it.
 */
class PreflightJob extends BaseJob
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

    protected function defaultDescription(): ?string
    {
        return Craft::t('pwa', 'Running the PWA preflight check');
    }
}
