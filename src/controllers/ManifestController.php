<?php

namespace justinholtweb\pwa\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\pwa\models\Manifest;
use justinholtweb\pwa\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The manifest — served to browsers, and edited in the control panel.
 *
 * One controller for both because they are two views of one document, and because splitting them
 * would mean two classes that have to agree about what a manifest is.
 */
class ManifestController extends Controller
{
    protected array|bool|int $allowAnonymous = ['serve'];

    /** What the browser fetches. */
    public function actionServe(): Response
    {
        if (!Plugin::getInstance()->getSettings()->enabled) {
            throw new NotFoundHttpException();
        }

        $site = Craft::$app->getSites()->getCurrentSite();

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->content = Plugin::getInstance()->manifests->json($site);

        $headers = $response->getHeaders();
        $headers->set('Content-Type', 'application/manifest+json; charset=UTF-8');
        $headers->set('Cache-Control', 'public, max-age=3600');

        return $response;
    }

    // ------------------------------------------------------------------------------- CP

    public function actionIndex(?string $siteHandle = null): Response
    {
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $sites = Craft::$app->getSites()->getEditableSites();
        $site = $siteHandle !== null
            ? (Craft::$app->getSites()->getSiteByHandle($siteHandle) ?? $sites[0])
            : Craft::$app->getSites()->getCurrentSite();

        $plugin = Plugin::getInstance();
        $manifest = $plugin->getSettings()->getManifest($site->uid);

        return $this->renderTemplate('pwa/manifest/index', [
            'site' => $site,
            'sites' => $sites,
            'manifest' => $manifest,
            'icons' => $plugin->icons->manifestIcons($manifest),
            'needsGenerating' => $plugin->icons->needsGenerating($manifest),
            'preview' => $plugin->manifests->json($site),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $request = $this->request;
        $siteUid = (string)$request->getRequiredBodyParam('siteUid');
        $settings = Plugin::getInstance()->getSettings();

        $manifest = $settings->getManifest($siteUid);
        $manifest->name = (string)$request->getBodyParam('name', '');
        $manifest->shortName = (string)$request->getBodyParam('shortName', '');
        $manifest->description = (string)$request->getBodyParam('description', '');
        $manifest->startUrl = (string)$request->getBodyParam('startUrl', '/');
        $manifest->scope = (string)$request->getBodyParam('scope', '');
        $manifest->display = (string)$request->getBodyParam('display', Manifest::DISPLAY_STANDALONE);
        $manifest->orientation = (string)$request->getBodyParam('orientation', 'any');
        $manifest->themeColor = (string)$request->getBodyParam('themeColor', '#111827');
        $manifest->backgroundColor = (string)$request->getBodyParam('backgroundColor', '#ffffff');
        $manifest->lang = (string)$request->getBodyParam('lang', '');
        $manifest->dir = (string)$request->getBodyParam('dir', 'auto');
        $manifest->id = (string)$request->getBodyParam('id', '');

        $categories = $request->getBodyParam('categories', '');
        $manifest->categories = is_array($categories)
            ? array_values(array_filter(array_map('trim', $categories)))
            : array_values(array_filter(array_map('trim', explode(',', (string)$categories))));

        $displayOverride = $request->getBodyParam('displayOverride', []);
        $manifest->displayOverride = array_values(array_filter((array)$displayOverride));

        $iconAssetId = $request->getBodyParam('iconAssetId');
        $manifest->iconAssetId = is_array($iconAssetId) ? (int)($iconAssetId[0] ?? 0) ?: null : ((int)$iconAssetId ?: null);

        $maskableAssetId = $request->getBodyParam('maskableAssetId');
        $manifest->maskableAssetId = is_array($maskableAssetId) ? (int)($maskableAssetId[0] ?? 0) ?: null : ((int)$maskableAssetId ?: null);

        $shortcuts = $request->getBodyParam('shortcuts', []);
        $manifest->shortcuts = array_values(array_filter(
            is_array($shortcuts) ? $shortcuts : [],
            static fn($row) => is_array($row) && trim((string)($row['name'] ?? '')) !== '',
        ));

        if (!Plugin::getInstance()->manifests->save($manifest)) {
            $this->setFailFlash(Craft::t('pwa', 'Couldn’t save the manifest.'));

            Craft::$app->getUrlManager()->setRouteParams(['manifest' => $manifest]);

            return null;
        }

        $this->setSuccessFlash(Craft::t('pwa', 'Manifest saved.'));

        return $this->redirectToPostedUrl();
    }

    /** Rebuilds the icon set on demand — the button next to "the icons look wrong". */
    public function actionRegenerateIcons(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $siteUid = (string)$this->request->getRequiredBodyParam('siteUid');
        $plugin = Plugin::getInstance();
        $manifest = $plugin->getSettings()->getManifest($siteUid);

        if ($manifest->getSourceAsset() === null) {
            return $this->asFailure(Craft::t('pwa', 'Choose a source image first.'));
        }

        $written = $plugin->icons->generate($manifest, $plugin->getSettings()->splashEnabled);

        if ($written === 0) {
            return $this->asFailure(Craft::t('pwa', 'No icons could be written. Check storage permissions and the log.'));
        }

        $plugin->serviceWorker->invalidate();

        return $this->asSuccess(Craft::t('pwa', '{count} files generated.', ['count' => $written]));
    }
}
