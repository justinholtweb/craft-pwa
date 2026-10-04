<?php

namespace justinholtweb\pwa\services;

use Craft;
use craft\base\Component;
use craft\elements\Asset;
use craft\helpers\Json;
use craft\models\Site;
use justinholtweb\pwa\models\Manifest;
use justinholtweb\pwa\Plugin;

/**
 * Builds and serves the web app manifest.
 *
 * The manifest is rendered on request rather than written to disk, which is a deliberate trade: it
 * costs a PHP request that a static file would not, and in exchange it can never be stale, never
 * be half-deployed, and never disagree with the settings somebody just saved. It is one small JSON
 * document fetched once per install, and Craft's own caching headers apply to it like anything
 * else.
 *
 * Everything site-specific goes through here rather than through the models, so that a multi-site
 * install produces one manifest per site with the right name, scope and icons in each.
 */
class Manifests extends Component
{
    /**
     * Manifests already built this request, by site UID.
     *
     * Building one queries for its two assets, and a single page asks for the same site's
     * manifest from the head tags, the runtime config, the worker config and the icons.
     *
     * @var array<string, Manifest>
     */
    private array $memo = [];

    /** One site's manifest, built once per request. */
    public function forSite(?Site $site = null): Manifest
    {
        $site ??= Craft::$app->getSites()->getCurrentSite();

        return $this->memo[$site->uid] ??= Plugin::getInstance()->getSettings()->getManifest($site->uid);
    }

    /** Forgets what was built, after a save or anything else that changes the settings. */
    public function reset(): void
    {
        $this->memo = [];
    }

    /**
     * The manifest for a site, as an array ready to be encoded.
     *
     * @return array<string, mixed>
     */
    public function build(?Site $site = null): array
    {
        $manifest = $this->forSite($site);

        $data = $manifest->toManifestArray();
        $icons = Plugin::getInstance()->icons->manifestIcons($manifest);

        if (!empty($icons)) {
            $data['icons'] = $icons;
        }

        $screenshots = $this->screenshots($manifest);

        if (!empty($screenshots)) {
            $data['screenshots'] = $screenshots;
        }

        // `gcm_sender_id` is not included, ever. It is the legacy Chrome push identifier, it is
        // wrong for VAPID, and copying it out of an old tutorial is the single most common reason
        // a subscription is refused with an opaque error.

        return $data;
    }

    /** The manifest as it is served — pretty-printed, because people do open it in a browser. */
    public function json(?Site $site = null): string
    {
        return Json::encode($this->build($site), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Screenshots, which are what turn Chrome's terse install bar into the richer dialog.
     *
     * Each needs its dimensions declared, so an asset whose width or height Craft does not know is
     * skipped rather than declared wrongly — a mismatched `sizes` is worse than a missing one, and
     * makes Chrome discard the whole array.
     *
     * @return array<int, array<string, mixed>>
     */
    private function screenshots(Manifest $manifest): array
    {
        $out = [];

        foreach ($manifest->screenshots as $screenshot) {
            $asset = !empty($screenshot['assetUid'])
                ? Asset::find()->uid((string)$screenshot['assetUid'])->status(null)->one()
                : (!empty($screenshot['assetId']) ? Craft::$app->getAssets()->getAssetById((int)$screenshot['assetId']) : null);

            if ($asset === null || !$asset->getWidth() || !$asset->getHeight()) {
                continue;
            }

            $url = $asset->getUrl();

            if ($url === null) {
                continue;
            }

            $entry = [
                'src' => $url,
                'sizes' => $asset->getWidth() . 'x' . $asset->getHeight(),
                'type' => $asset->getMimeType() ?? 'image/png',
            ];

            $formFactor = trim((string)($screenshot['formFactor'] ?? ''));

            if ($formFactor !== '') {
                $entry['form_factor'] = $formFactor;
            }

            $label = trim((string)($screenshot['label'] ?? ''));

            if ($label !== '') {
                $entry['label'] = $label;
            }

            $out[] = $entry;
        }

        return $out;
    }

    /**
     * Saves one site's manifest back into project config, regenerating icons if the source changed.
     *
     * No cache bump is needed: the worker's cache version includes a hash of the configuration it
     * was generated from, so a changed manifest renames every cache on its own.
     */
    public function save(Manifest $manifest): bool
    {
        if (!$manifest->validate()) {
            return false;
        }

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $siteUid = (string)$manifest->siteUid;

        // Assets by UID: an element ID synced to another environment points at the wrong asset.
        $config = $manifest->toArray([
            'name', 'shortName', 'description', 'startUrl', 'scope', 'display', 'displayOverride',
            'orientation', 'themeColor', 'backgroundColor', 'lang', 'dir', 'categories',
            'shortcuts', 'screenshots', 'id',
        ]) + $manifest->assetUids();
        $config['screenshots'] = array_map(static function($screenshot) {
            if (is_array($screenshot) && !empty($screenshot['assetId']) && empty($screenshot['assetUid'])) {
                $screenshot['assetUid'] = Asset::find()->id((int)$screenshot['assetId'])->status(null)->site('*')->one()?->uid;
                unset($screenshot['assetId']);
            }

            return $screenshot;
        }, (array)$config['screenshots']);

        $manifests = $settings->manifests;
        $manifests[$siteUid] = $config;
        $settings->manifests = $manifests;

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray())) {
            return false;
        }

        $this->reset();

        // New icons at the same URLs change nothing the worker was configured with, so the
        // cached copies have to be thrown away by hand.
        if ($plugin->icons->needsGenerating($manifest) && $plugin->icons->generate($manifest, $settings->splashEnabled) > 0) {
            $plugin->serviceWorker->invalidate();
        }

        return true;
    }
}
