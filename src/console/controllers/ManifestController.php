<?php

namespace justinholtweb\pwa\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\pwa\Plugin;
use yii\console\ExitCode;

/**
 * Prints what the browser would be served.
 *
 * Useful precisely when the site cannot be reached over HTTP — behind basic auth, inside a
 * container, mid-deploy — which is exactly when the preflight check has to skip half its work.
 */
class ManifestController extends Controller
{
    public string $site = '';

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['site']);
    }

    /** The manifest JSON. */
    public function actionDump(): int
    {
        $site = $this->site !== ''
            ? Craft::$app->getSites()->getSiteByHandle($this->site)
            : Craft::$app->getSites()->getPrimarySite();

        if ($site === null) {
            $this->stderr("No such site.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $this->stdout(Plugin::getInstance()->manifests->json($site) . "\n");

        return ExitCode::OK;
    }

    /** The generated service worker, as it would be served. */
    public function actionWorker(): int
    {
        $site = $this->site !== ''
            ? Craft::$app->getSites()->getSiteByHandle($this->site)
            : Craft::$app->getSites()->getPrimarySite();

        if ($site === null) {
            $this->stderr("No such site.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $this->stdout(Plugin::getInstance()->serviceWorker->render($site) . "\n");

        return ExitCode::OK;
    }
}
