<?php

namespace justinholtweb\pwa\services;

use Craft;
use craft\base\Component;
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
     * The manifest for a site, as an array ready to be encoded.
     *
     * @return array<string, mixed>
     */
    public function build(?Site $site = null): array
    {
        $site ??= Craft::$app->getSites()->getCurrentSite();
        $manifest = Plugin::getInstance()->getSettings()->getManifest($site->uid);

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
            $assetId = (int)($screenshot['assetId'] ?? 0);

            if ($assetId === 0) {
                continue;
            }

            $asset = Craft::$app->getAssets()->getAssetById($assetId);

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
     * Bumping the cache version here rather than in the settings screen is the point: a manifest
     * change that does not invalidate the service worker's caches is a change nobody sees until
     * they clear site data.
     */
    public function save(Manifest $manifest): bool
    {
        if (!$manifest->validate()) {
            return false;
        }

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $siteUid = (string)$manifest->siteUid;

        $config = $manifest->toArray([
            'name', 'shortName', 'description', 'startUrl', 'scope', 'display', 'displayOverride',
            'orientation', 'themeColor', 'backgroundColor', 'lang', 'dir', 'categories',
            'iconAssetId', 'maskableAssetId', 'shortcuts', 'screenshots', 'id',
        ]);

        $manifests = $settings->manifests;
        $manifests[$siteUid] = $config;
        $settings->manifests = $manifests;
        $settings->cacheVersion++;

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray())) {
            return false;
        }

        if ($plugin->icons->needsGenerating($manifest)) {
            $plugin->icons->generate($manifest, $settings->splashEnabled);
        }

        return true;
    }
}
