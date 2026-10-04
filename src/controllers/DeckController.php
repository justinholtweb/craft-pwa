<?php

namespace justinholtweb\pwa\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\pwa\Plugin;
use yii\web\Response;

/**
 * The flight deck: everything about the app's current state on one screen.
 *
 * Ordered by what somebody actually opens it to find out — is it installable, is anything broken,
 * is anybody installing it, and what are they hitting offline. The last of those is the most
 * useful number in the plugin, because it is a ready-made list of what to precache.
 */
class DeckController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_VIEW);

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $site = Craft::$app->getSites()->getCurrentSite();
        $manifest = $plugin->manifests->forSite($site);

        $latest = $plugin->preflight->getLatest($site->id);

        return $this->renderTemplate('pwa/_deck/index', [
            'cacheCounter' => $plugin->serviceWorker->counter(),
            'site' => $site,
            'settings' => $settings,
            'manifest' => $manifest,
            'audit' => $latest,
            'icons' => $plugin->icons->manifestIcons($manifest),
            'manifestUrl' => $plugin->serviceWorker->manifestUrl($site),
            'workerUrl' => $plugin->serviceWorker->scriptUrl($site),
            'offlineUrl' => $plugin->serviceWorker->offlineUrl($site),
            'precache' => $plugin->serviceWorker->precache($site),
            'totals' => $plugin->events->totals(30, $site->id),
            'installs' => $plugin->events->series('install', 30, $site->id),
            'offlinePaths' => $plugin->events->topOfflinePaths(30, 10, $site->id),
            'subscribers' => $plugin->isPro() && $settings->pushEnabled
                ? $plugin->push->countSubscribers($site->id)
                : null,
            'isPro' => $plugin->isPro(),
        ]);
    }

    /**
     * Bumps the cache version, which is the supported way to say "throw away what is out there".
     *
     * Every installed copy of the worker names its caches after this number, so raising it makes
     * the next activation delete all of them. It is the button for "I deployed a change and people
     * are still seeing the old thing".
     */
    public function actionInvalidate(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        Plugin::getInstance()->serviceWorker->invalidate();

        return $this->asSuccess(Craft::t('pwa', 'Caches invalidated. Visitors get fresh copies on their next visit.'));
    }
}
