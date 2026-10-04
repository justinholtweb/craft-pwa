<?php

namespace justinholtweb\pwa\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\pwa\helpers\Files;
use justinholtweb\pwa\Plugin;
use yii\console\ExitCode;

/**
 * Regenerates the icon set.
 *
 * Belongs in a deploy script for anybody whose generated files live in the web root and whose web
 * root is rebuilt on every deploy — the icons are derived data, and derived data that only exists
 * because somebody once clicked a button is derived data that goes missing.
 */
class IconsController extends Controller
{
    /** Limit to one site handle. */
    public string $site = '';

    /** Skip the iOS splash screens, which are the slow part. */
    public bool $noSplash = false;

    /** Rebuild even when the stamp says nothing has changed. */
    public bool $force = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['site', 'noSplash', 'force']);
    }

    public function actionGenerate(): int
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        // The web process writes into the web root when it can. From a terminal the web root is
        // only known if somebody says where it is, and guessing wrong means writing the icons to
        // storage/ while the site serves them from public/ — a set that exists and is never seen.
        if (Files::webroot() === null) {
            $this->stderr("The web root could not be resolved from the command line, so the icons would be written somewhere the site does not serve them from.\nSet CRAFT_WEB_ROOT (or the @webroot alias) to the site's public directory and run this again.\n", Console::FG_RED);

            return ExitCode::CONFIG;
        }

        $sites = $this->site !== ''
            ? array_filter([Craft::$app->getSites()->getSiteByHandle($this->site)])
            : Craft::$app->getSites()->getAllSites();

        if (empty($sites)) {
            $this->stderr("No such site.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $total = 0;

        foreach ($sites as $site) {
            $manifest = $plugin->manifests->forSite($site);

            if ($manifest->getSourceAsset() === null) {
                $this->stdout($site->getName() . ": no icon source configured, skipped.\n", Console::FG_GREY);
                continue;
            }

            if (!$this->force && !$plugin->icons->needsGenerating($manifest)) {
                $this->stdout($site->getName() . ": already up to date.\n", Console::FG_GREY);
                continue;
            }

            $written = $plugin->icons->generate($manifest, $settings->splashEnabled && !$this->noSplash);
            $total += $written;

            $this->stdout($site->getName() . ": {$written} files.\n", $written > 0 ? Console::FG_GREEN : Console::FG_RED);
        }

        if ($total > 0) {
            $plugin->serviceWorker->invalidate();
        }

        return ExitCode::OK;
    }
}
