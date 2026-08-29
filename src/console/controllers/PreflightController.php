<?php

namespace justinholtweb\pwa\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\pwa\helpers\Cadence;
use justinholtweb\pwa\models\Check;
use justinholtweb\pwa\Plugin;
use yii\console\ExitCode;

/**
 * The preflight check, from a terminal.
 *
 * Exists to be run in a deploy pipeline. `pwa/preflight/run` exits non-zero when something
 * blocking is failing, which is the whole point: a deploy that quietly breaks the manifest is not
 * something anybody notices from the outside for weeks.
 */
class PreflightController extends Controller
{
    /** Restrict the check to one site handle. */
    public string $site = '';

    /** Exit non-zero on warnings too, not only blocking failures. */
    public bool $strict = false;

    /** Store the result in the audit history. */
    public bool $save = true;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'run' => ['site', 'strict', 'save'],
            default => [],
        });
    }

    /**
     * Checks the site and prints the report.
     */
    public function actionRun(): int
    {
        $plugin = Plugin::getInstance();
        $sites = $this->site !== ''
            ? array_filter([Craft::$app->getSites()->getSiteByHandle($this->site)])
            : Craft::$app->getSites()->getAllSites();

        if (empty($sites)) {
            $this->stderr("No such site.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $worst = ExitCode::OK;

        foreach ($sites as $site) {
            $audit = $plugin->preflight->run($site, 'console');

            if ($this->save) {
                $plugin->preflight->save($audit);
            }

            $this->stdout("\n" . $site->getName() . ' — ', Console::BOLD);
            $this->stdout($audit->score . '/100', $audit->score >= 90 ? Console::FG_GREEN : ($audit->score >= 70 ? Console::FG_YELLOW : Console::FG_RED));
            $this->stdout($audit->installable ? "  installable\n\n" : "  NOT INSTALLABLE\n\n", $audit->installable ? Console::FG_GREEN : Console::FG_RED);

            foreach ($audit->checks as $check) {
                [$mark, $colour] = match (true) {
                    $check->status === Check::PASS => ['  ok  ', Console::FG_GREEN],
                    $check->status === Check::WARN => [' warn ', Console::FG_YELLOW],
                    $check->status === Check::FAIL && $check->blocking => [' BLOCK', Console::FG_RED],
                    $check->status === Check::FAIL => [' fail ', Console::FG_RED],
                    default => [' skip ', Console::FG_GREY],
                };

                $this->stdout($mark, $colour);
                $this->stdout(' ' . $check->label . ' — ' . $check->summary . "\n");

                if ($check->remediation !== '') {
                    $this->stdout('        → ' . $check->remediation . "\n", Console::FG_GREY);
                }
            }

            if (!$audit->installable) {
                $worst = ExitCode::SOFTWARE;
            } elseif ($this->strict && ($audit->failed > 0 || $audit->warned > 0) && $worst === ExitCode::OK) {
                $worst = ExitCode::SOFTWARE;
            }
        }

        return $worst;
    }

    /**
     * Runs the check only if the schedule says one is due.
     *
     * For a cron entry. Safe to call every hour: it does nothing until the configured hour comes
     * round, and nothing again until the next occurrence.
     */
    public function actionDue(): int
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (!$plugin->isPro()) {
            $this->stdout("Scheduled preflight requires PWA Pro.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        if (!$settings->preflightScheduled) {
            $this->stdout("Scheduled preflight is switched off.\n");

            return ExitCode::OK;
        }

        $lastScheduled = null;

        foreach ($plugin->preflight->getAudits(null, 20) as $audit) {
            if ($audit->trigger === 'scheduled') {
                $lastScheduled = $audit->dateCreated;
                break;
            }
        }

        if (!Cadence::isDue($settings->preflightCadence, $settings->preflightHour, $settings->preflightWeekday, $lastScheduled)) {
            $this->stdout("Not due yet.\n", Console::FG_GREY);

            return ExitCode::OK;
        }

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $audit = $plugin->preflight->run($site, 'scheduled');
            $plugin->preflight->save($audit);
            $plugin->notifications->sendPreflightReport($audit);

            $this->stdout($site->getName() . ': ' . $audit->score . "/100\n");
        }

        return ExitCode::OK;
    }
}
