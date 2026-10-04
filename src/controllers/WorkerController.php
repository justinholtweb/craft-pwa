<?php

namespace justinholtweb\pwa\controllers;

use Craft;
use craft\helpers\FileHelper;
use craft\web\Controller;
use justinholtweb\pwa\helpers\Files;
use justinholtweb\pwa\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Serves the service worker, the offline page, and the generated files.
 *
 * All four actions are anonymous by necessity: a service worker is fetched by the browser without
 * a session, and an offline page that required one would be the least useful page on the site.
 */
class WorkerController extends Controller
{
    protected array|bool|int $allowAnonymous = true;

    /**
     * The service worker itself.
     *
     * The two headers here are not decoration. `Service-Worker-Allowed` is what lets a worker
     * claim a scope broader than its own directory, and `no-cache` is what lets a deploy actually
     * replace it — browsers cap service worker caching at 24 hours, but the CDN in front does not.
     */
    public function actionServe(): Response
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->enabled || !$settings->serviceWorkerEnabled) {
            throw new NotFoundHttpException();
        }

        $site = Craft::$app->getSites()->getCurrentSite();
        $body = Plugin::getInstance()->serviceWorker->render($site);

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->content = $body;

        $headers = $response->getHeaders();
        $headers->set('Content-Type', 'text/javascript; charset=UTF-8');
        $headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $headers->set('Service-Worker-Allowed', Plugin::getInstance()->manifests->forSite($site)->getEffectiveScope());
        $headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    /**
     * The plugin's own offline page.
     *
     * Only reached when a site has not set one of its own. It is deliberately plain and entirely
     * self-contained — it is rendered when there is no network, so a stylesheet it referenced
     * would be one more thing to have gone missing.
     */
    public function actionOffline(): Response
    {
        $site = Craft::$app->getSites()->getCurrentSite();
        $manifest = Plugin::getInstance()->manifests->forSite($site);

        $response = $this->renderTemplate('pwa/_offline', [
            'manifest' => $manifest,
            'icon' => Plugin::getInstance()->icons->iconUrl($site->uid, 'icon-192.png'),
        ], \craft\web\View::TEMPLATE_MODE_CP);

        $response->getHeaders()->set('Cache-Control', 'public, max-age=3600');

        return $response;
    }

    /**
     * Generated files, when they could not be written to the web root.
     *
     * The path is resolved against the plugin's own directory and then checked to still be inside
     * it, because a path parameter that reaches the filesystem is the one place in this plugin
     * where a mistake is a security bug rather than a broken icon.
     */
    public function actionFile(string $path): Response
    {
        $target = Files::within(Files::basePath(), $path);

        if ($target === null || !is_file($target)) {
            throw new NotFoundHttpException();
        }

        $mime = FileHelper::getMimeType($target) ?? 'application/octet-stream';

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->content = (string)file_get_contents($target);
        $response->getHeaders()->set('Content-Type', $mime);
        $response->getHeaders()->set('Cache-Control', 'public, max-age=31536000, immutable');

        return $response;
    }

    /** The page runtime, for installs where Craft's asset publishing is unavailable. */
    public function actionRuntime(): Response
    {
        $file = dirname(__DIR__) . '/resources/runtime/pwa.js';

        if (!is_file($file)) {
            throw new NotFoundHttpException();
        }

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->content = (string)file_get_contents($file);
        $response->getHeaders()->set('Content-Type', 'text/javascript; charset=UTF-8');
        $response->getHeaders()->set('Cache-Control', 'public, max-age=3600');

        return $response;
    }
}
