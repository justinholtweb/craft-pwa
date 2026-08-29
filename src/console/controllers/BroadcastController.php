<?php

namespace justinholtweb\pwa\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\pwa\models\Campaign;
use justinholtweb\pwa\Plugin;
use yii\console\ExitCode;

/**
 * Sends broadcasts from the command line, and releases scheduled ones.
 *
 * `pwa/broadcast/due` is the cron half of scheduling: a campaign given a send time sits as
 * `scheduled` until something notices the time has passed. Sending is still done by the queue —
 * this only moves campaigns onto it.
 */
class BroadcastController extends Controller
{
    /**
     * The campaign to send.
     *
     * Not `--id`: `$id` is the controller's own identifier on yii\base\Controller, and an option
     * of that name overwrites it.
     */
    public ?int $campaign = null;

    /** Show what would be sent, to how many, without sending it. */
    public bool $dryRun = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'send' => ['campaign', 'dryRun'],
            default => [],
        });
    }

    public function actionSend(): int
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->isPro()) {
            $this->stderr("Push notifications require PWA Pro.\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        if ($this->campaign === null) {
            $this->stderr("Pass --campaign=<id>.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $campaign = $plugin->campaigns->getCampaignById($this->campaign);

        if ($campaign === null) {
            $this->stderr("No such campaign.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $targets = count($plugin->push->getSubscribers($campaign->siteId, $campaign->topics));

        $this->stdout("“{$campaign->title}” → {$targets} device(s)\n");

        if ($this->dryRun) {
            $this->stdout("Dry run; nothing was sent.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        if ($campaign->status === Campaign::STATUS_SENT || $campaign->status === Campaign::STATUS_SENDING) {
            $this->stderr("That campaign has already been sent.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        if (!$plugin->campaigns->broadcast($campaign)) {
            $this->stderr("Could not queue the broadcast.\n", Console::FG_RED);

            return ExitCode::SOFTWARE;
        }

        $this->stdout("Queued.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /** Queues every scheduled campaign whose time has come. */
    public function actionDue(): int
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->isPro()) {
            return ExitCode::OK;
        }

        $due = $plugin->campaigns->getDue();

        if (empty($due)) {
            $this->stdout("Nothing due.\n", Console::FG_GREY);

            return ExitCode::OK;
        }

        foreach ($due as $campaign) {
            if ($plugin->campaigns->broadcast($campaign)) {
                $this->stdout("Queued “{$campaign->title}” for {$campaign->targeted} device(s).\n", Console::FG_GREEN);
            }
        }

        return ExitCode::OK;
    }
}
