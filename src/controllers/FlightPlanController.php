<?php

namespace justinholtweb\pwa\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\pwa\models\Route;
use justinholtweb\pwa\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * The flight plan — the ordered rules the service worker fetches by.
 *
 * Editing them is Pro. Lite runs the defaults, which are the same rules everybody would write on
 * their first attempt, and which are deliberately conservative: pages from the network, assets
 * from the cache, control panel never touched.
 *
 * The route tester on this screen runs the *PHP* twin of the worker's matcher against a URL you
 * type. That is worth a moment: the two implementations exist because one has to run in a browser
 * and one has to run in the control panel, and they are kept honest by a shared table of cases in
 * the test suite rather than by hoping.
 */
class FlightPlanController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        return $this->renderTemplate('pwa/flight-plan/index', [
            'settings' => $settings,
            'routes' => $settings->getRoutes(),
            'isCustom' => !empty($settings->routes),
            'isPro' => $plugin->isPro(),
            'strategies' => [
                Route::STRATEGY_NETWORK_FIRST => Craft::t('pwa', 'Network first — try the network, fall back to the cache'),
                Route::STRATEGY_CACHE_FIRST => Craft::t('pwa', 'Cache first — serve what is stored, fetch only if there is nothing'),
                Route::STRATEGY_STALE_WHILE_REVALIDATE => Craft::t('pwa', 'Stale while revalidate — serve what is stored, refresh in the background'),
                Route::STRATEGY_NETWORK_ONLY => Craft::t('pwa', 'Network only — never touch the cache'),
                Route::STRATEGY_CACHE_ONLY => Craft::t('pwa', 'Cache only — never touch the network'),
            ],
            'matchTypes' => [
                Route::MATCH_PATH => Craft::t('pwa', 'Path'),
                Route::MATCH_EXTENSION => Craft::t('pwa', 'File extension'),
                Route::MATCH_DESTINATION => Craft::t('pwa', 'Request type'),
                Route::MATCH_HOST => Craft::t('pwa', 'Host'),
            ],
            'caches' => [
                Route::CACHE_PAGES => Craft::t('pwa', 'Pages'),
                Route::CACHE_ASSETS => Craft::t('pwa', 'Assets'),
                Route::CACHE_IMAGES => Craft::t('pwa', 'Images'),
            ],
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();

        if (!$plugin->isPro()) {
            throw new ForbiddenHttpException('Editing the flight plan requires PWA Pro.');
        }

        $settings = $plugin->getSettings();
        $posted = $this->request->getBodyParam('routes', []);
        $routes = [];

        foreach (is_array($posted) ? $posted : [] as $row) {
            if (!is_array($row) || trim((string)($row['pattern'] ?? '')) === '') {
                continue;
            }

            $route = new Route([
                'label' => trim((string)($row['label'] ?? '')),
                'match' => (string)($row['match'] ?? Route::MATCH_PATH),
                'pattern' => trim((string)$row['pattern']),
                'strategy' => (string)($row['strategy'] ?? Route::STRATEGY_NETWORK_FIRST),
                'cache' => (string)($row['cache'] ?? Route::CACHE_PAGES),
                'networkTimeout' => (float)($row['networkTimeout'] ?? 3),
                'offlineFallback' => !empty($row['offlineFallback']),
                'enabled' => !isset($row['enabled']) || !empty($row['enabled']),
            ]);

            if (!$route->validate()) {
                $this->setFailFlash(Craft::t('pwa', 'One of the rules is not valid: {error}', [
                    'error' => implode(' ', $route->getFirstErrors()),
                ]));

                return null;
            }

            $routes[] = $route->toArray([
                'label', 'match', 'pattern', 'strategy', 'cache', 'networkTimeout', 'offlineFallback', 'enabled',
            ]);
        }

        $settings->routes = $routes;

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray())) {
            $this->setFailFlash(Craft::t('pwa', 'Couldn’t save the flight plan.'));
            return null;
        }

        // Any change here changes what the worker does, so every cache it holds is now describing
        // a plan that no longer exists.
        $plugin->serviceWorker->invalidate();

        $this->setSuccessFlash(Craft::t('pwa', 'Flight plan saved.'));

        return $this->redirectToPostedUrl();
    }

    /** Resets to the shipped defaults, which is also how a Pro site goes back to Lite behaviour. */
    public function actionReset(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $settings->routes = [];

        Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray());
        $plugin->serviceWorker->invalidate();

        return $this->asSuccess(Craft::t('pwa', 'Flight plan reset to the defaults.'));
    }

    /** Which rule would claim a given URL — the answer to "why is this page not caching?". */
    public function actionTest(): Response
    {
        $this->requireAcceptsJson();

        $path = (string)$this->request->getRequiredBodyParam('path');
        $destination = (string)$this->request->getBodyParam('destination', 'document');
        $host = (string)parse_url($path, PHP_URL_HOST) ?: (string)Craft::$app->getRequest()->getHostName();
        $pathOnly = parse_url($path, PHP_URL_PATH) ?: $path;

        foreach (Plugin::getInstance()->getSettings()->getRoutes() as $index => $route) {
            if ($route->matches($pathOnly, $destination, $host)) {
                return $this->asJson([
                    'matched' => true,
                    'index' => $index,
                    'label' => $route->label,
                    'strategy' => $route->strategy,
                    'strategyLabel' => $route->getStrategyLabel(),
                    'cache' => $route->cache,
                ]);
            }
        }

        return $this->asJson(['matched' => false]);
    }
}
