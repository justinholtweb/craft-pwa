<?php

namespace justinholtweb\pwa\controllers;

use Craft;
use craft\models\Site;
use craft\web\Controller;
use justinholtweb\pwa\models\Manifest;
use justinholtweb\pwa\Plugin;
use yii\web\ForbiddenHttpException;
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

    public function actionIndex(?string $siteHandle = null, ?Manifest $manifest = null): Response
    {
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $sites = Craft::$app->getSites()->getEditableSites();

        if (empty($sites)) {
            throw new ForbiddenHttpException('You cannot edit any site.');
        }

        // A failed save re-renders with what was posted, for the site it was posted for.
        if ($manifest !== null) {
            $site = $this->editableSite($manifest->siteUid);
        } elseif ($siteHandle !== null) {
            $site = null;

            foreach ($sites as $candidate) {
                if ($candidate->handle === $siteHandle) {
                    $site = $candidate;
                    break;
                }
            }

            if ($site === null) {
                throw new NotFoundHttpException('Site not found');
            }
        } else {
            $current = Craft::$app->getSites()->getCurrentSite();
            $site = in_array($current->id, array_map(static fn($s) => $s->id, $sites), true) ? $current : $sites[0];
        }

        $plugin = Plugin::getInstance();
        $manifest ??= $plugin->manifests->forSite($site);

        return $this->renderTemplate('pwa/_manifest/index', [
            'site' => $site,
            'sites' => $sites,
            'manifest' => $manifest,
            'icons' => $plugin->icons->manifestIcons($manifest),
            'needsGenerating' => $plugin->icons->needsGenerating($manifest),
            'preview' => $plugin->manifests->json($site),
            'readOnly' => !Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);
        $this->requireAdminChanges();

        $request = $this->request;
        $site = $this->editableSite((string)$request->getRequiredBodyParam('siteUid'));

        // A copy: the memoized one also renders the preview, which must show what is saved.
        $manifest = clone Plugin::getInstance()->manifests->forSite($site);
        $manifest->name = (string)$request->getBodyParam('name', '');
        $manifest->shortName = (string)$request->getBodyParam('shortName', '');
        $manifest->description = (string)$request->getBodyParam('description', '');
        $manifest->startUrl = (string)$request->getBodyParam('startUrl', '/');
        $manifest->scope = (string)$request->getBodyParam('scope', '');
        $manifest->display = (string)$request->getBodyParam('display', Manifest::DISPLAY_STANDALONE);
        $manifest->orientation = (string)$request->getBodyParam('orientation', 'any');
        $manifest->themeColor = Manifest::normalizeColor((string)$request->getBodyParam('themeColor', '#111827'));
        $manifest->backgroundColor = Manifest::normalizeColor((string)$request->getBodyParam('backgroundColor', '#ffffff'));
        $manifest->lang = (string)$request->getBodyParam('lang', '');
        $manifest->dir = (string)$request->getBodyParam('dir', 'auto');
        $manifest->id = (string)$request->getBodyParam('id', '');

        $categories = $request->getBodyParam('categories', '');
        $manifest->categories = is_array($categories)
            ? array_values(array_filter(array_map('trim', $categories)))
            : array_values(array_filter(array_map('trim', explode(',', (string)$categories))));

        $displayOverride = $request->getBodyParam('displayOverride', []);
        $manifest->displayOverride = array_values(array_filter((array)$displayOverride));

        $manifest->iconAssetId = $this->postedAssetId('iconAssetId');
        $manifest->maskableAssetId = $this->postedAssetId('maskableAssetId');

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

        $site = $this->editableSite((string)$this->request->getRequiredBodyParam('siteUid'));
        $plugin = Plugin::getInstance();
        $manifest = $plugin->manifests->forSite($site);

        if ($manifest->getSourceAsset() === null) {
            return $this->asFailure(Craft::t('pwa', 'Choose a source image first.'));
        }

        $written = $plugin->icons->generate($manifest, $plugin->getSettings()->splashEnabled);

        if ($written === 0) {
            return $this->asFailure(Craft::t('pwa', 'No icons could be written. Check storage permissions and the log.'));
        }

        $plugin->serviceWorker->invalidate();

        return $this->asSuccess(Craft::t('pwa', '{count, plural, =1{# file} other{# files}} generated.', ['count' => $written]));
    }

    /**
     * A site the current user may edit, by UID — or a 404.
     *
     * The UID arrives in the request body and ends up as a directory name the icon generator
     * clears, so it is looked up rather than trusted: anything that is not one of the user's own
     * editable sites never gets as far as the filesystem.
     */
    private function editableSite(?string $siteUid): Site
    {
        foreach (Craft::$app->getSites()->getEditableSites() as $site) {
            if ($siteUid !== null && $site->uid === $siteUid) {
                return $site;
            }
        }

        throw new NotFoundHttpException('Site not found');
    }

    /**
     * An icon asset chosen in the element select, checked against the person choosing it.
     *
     * The ID is a posted integer; without this, anybody with the manage permission could name an
     * asset in a volume they cannot see and have it published as the site's icon.
     */
    private function postedAssetId(string $name): ?int
    {
        $value = $this->request->getBodyParam($name);
        $id = is_array($value) ? (int)($value[0] ?? 0) : (int)$value;

        if ($id <= 0) {
            return null;
        }

        $asset = Craft::$app->getAssets()->getAssetById($id);

        if ($asset === null || !Craft::$app->getElements()->canView($asset, Craft::$app->getUser()->getIdentity())) {
            throw new ForbiddenHttpException('You cannot use that asset.');
        }

        return $id;
    }

    /**
     * Manifests and the flight plan are project config. Where admin changes are off they arrive by
     * deploy; a save here used to throw from deep inside project config instead of saying so.
     */
    private function requireAdminChanges(): void
    {
        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            throw new ForbiddenHttpException(Craft::t('pwa', 'This is project config, and admin changes are not allowed on this environment.'));
        }
    }
}
