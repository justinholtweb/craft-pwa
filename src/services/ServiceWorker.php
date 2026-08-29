<?php

namespace justinholtweb\pwa\services;

use Craft;
use craft\base\Component;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\models\Site;
use justinholtweb\pwa\helpers\Files;
use justinholtweb\pwa\models\Route;
use justinholtweb\pwa\Plugin;

/**
 * Renders the service worker.
 *
 * The worker's *logic* ships with the plugin as a fixed file; only its configuration is generated.
 * That split is the whole design. Generating JavaScript from PHP produces code nobody can debug in
 * a browser, and shipping a worker that reads its settings over the network produces one that does
 * not work offline — which for a service worker is the only thing that matters. So the file is
 * static, reviewable and testable, and the flight plan arrives as one JSON object at the top.
 *
 * It is served from a route rather than written to disk because the file must sit at the web root
 * to claim a root scope, and a plugin that writes to the web root on every settings save is a
 * plugin that breaks on the first read-only deploy.
 */
class ServiceWorker extends Component
{
    private const PLACEHOLDER = '/*__PWA_CONFIG__*/ {}';

    /** The finished worker, ready to be served. */
    public function render(?Site $site = null): string
    {
        $site ??= Craft::$app->getSites()->getCurrentSite();
        $source = file_get_contents(dirname(__DIR__) . '/resources/sw.js');

        if ($source === false) {
            Plugin::error('The service worker source could not be read.');
            return '';
        }

        $config = Json::encode($this->config($site), JSON_UNESCAPED_SLASHES);

        return str_replace(self::PLACEHOLDER, $config, $source);
    }

    /**
     * Everything the worker needs to know, and nothing it does not.
     *
     * @return array<string, mixed>
     */
    public function config(Site $site): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $manifest = $settings->getManifest($site->uid);

        $routes = array_map(
            static fn(Route $route) => $route->toWorkerArray(),
            array_values(array_filter($settings->getRoutes(), static fn(Route $r) => $r->enabled)),
        );

        $config = [
            'version' => $settings->cacheVersion,
            'scope' => $manifest->getEffectiveScope(),
            'appName' => $manifest->getEffectiveName(),
            'startUrl' => $manifest->getEffectiveStartUrl(),
            'routes' => $routes,
            'precache' => $this->precache($site),
            'offlineUrl' => $this->offlineUrl($site),
            'limits' => [
                'pages' => $settings->maxCachedPages,
                'assets' => $settings->maxCachedAssets,
                'images' => $settings->maxCachedImages,
            ],
            'lifetimeDays' => $settings->cacheLifetimeDays,

            // The waiting worker takes over immediately rather than on the next full close. For a
            // content site that is right: the alternative is a visitor stuck on the previous
            // version until every tab is closed, which on a phone is approximately never.
            'skipWaiting' => true,

            'eventUrl' => $settings->trackEvents ? UrlHelper::actionUrl('pwa/events/record') : null,
        ];

        if ($settings->pushEnabled && $plugin->isPro()) {
            $config['vapidPublicKey'] = $plugin->push->getPublicKey();
            $config['subscribeUrl'] = UrlHelper::actionUrl('pwa/push/subscribe');
            $config['pushIcon'] = $settings->pushIcon ?: ($plugin->icons->iconUrl($site->uid, 'icon-192.png') ?? '');
            $config['pushBadge'] = $settings->pushBadge;
        }

        return $config;
    }

    /**
     * What is fetched when the worker installs.
     *
     * Kept short by default. Every URL here is downloaded before the worker activates, so a
     * generous precache list turns the first visit into a stall — and the app shell is precisely
     * the part that a page visit would fetch anyway. What genuinely belongs here is the offline
     * page (which by definition is never visited before it is needed) and the icons.
     *
     * @return string[]
     */
    public function precache(Site $site): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $urls = [];

        if ($settings->precacheEssentials) {
            $offline = $this->offlineUrl($site);

            if ($offline !== null) {
                $urls[] = $offline;
            }

            foreach (['icon-192.png', 'icon-512.png', 'apple-touch-icon.png'] as $icon) {
                $url = $plugin->icons->iconUrl($site->uid, $icon);

                if ($url !== null) {
                    $urls[] = $url;
                }
            }
        }

        foreach ($settings->precache as $uri) {
            $uri = trim((string)$uri);

            if ($uri === '') {
                continue;
            }

            $urls[] = str_contains($uri, '://') ? $uri : UrlHelper::siteUrl($uri, null, null, $site->id);
        }

        return array_values(array_unique($urls));
    }

    /** Where the offline fallback lives for a site — the configured page, or the plugin's own. */
    public function offlineUrl(Site $site): ?string
    {
        $settings = Plugin::getInstance()->getSettings();
        $uri = trim((string)($settings->offlineUri[$site->uid] ?? ''));

        if ($uri === '') {
            return UrlHelper::siteUrl(Files::DIR . '/offline', null, null, $site->id);
        }

        return str_contains($uri, '://') ? $uri : UrlHelper::siteUrl($uri, null, null, $site->id);
    }

    /** The URL the page registers, honouring a custom filename. */
    public function scriptUrl(?Site $site = null): string
    {
        $site ??= Craft::$app->getSites()->getCurrentSite();
        $path = ltrim(Plugin::getInstance()->getSettings()->serviceWorkerPath, '/');

        // Deliberately built from the root rather than the site's base URL. A worker served under
        // a site's subdirectory can only ever control that subdirectory, and the scope it needs is
        // decided by the manifest, not by which site rendered the page.
        return UrlHelper::siteUrl($path, null, null, $site->id);
    }

    public function manifestUrl(?Site $site = null): string
    {
        $site ??= Craft::$app->getSites()->getCurrentSite();

        return UrlHelper::siteUrl(ltrim(Plugin::getInstance()->getSettings()->manifestPath, '/'), null, null, $site->id);
    }

    /**
     * Bumps the cache version, which is how anything invalidates the worker's caches.
     *
     * Called whenever the flight plan, the precache list or the manifest changes. It is a single
     * integer because it has one job: to make every cache name different from the ones the
     * previous worker was using, so activation cleans them up.
     */
    public function invalidate(): void
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $settings->cacheVersion++;

        Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray());

        Plugin::info("Cache version bumped to {$settings->cacheVersion}.");
    }
}
