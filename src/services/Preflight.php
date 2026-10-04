<?php

namespace justinholtweb\pwa\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\App;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\models\Site;
use DateTime;
use GuzzleHttp\Client;
use justinholtweb\pwa\models\Audit;
use justinholtweb\pwa\models\Check;
use justinholtweb\pwa\models\Manifest;
use justinholtweb\pwa\models\Route;
use justinholtweb\pwa\Plugin;
use justinholtweb\pwa\records\AuditRecord;
use Throwable;

/**
 * The preflight check.
 *
 * Everything here is verified over HTTP against the running site rather than read out of the
 * settings that produced it. That is the entire point. The settings always look right — they are
 * what somebody just typed — and the manifest is still 404ing because of a rewrite rule, or the
 * service worker is being served with an hour of cache control, or the start URL redirects, or the
 * icon is on a CDN and therefore cross-origin. None of that is visible from inside PHP's own
 * memory, and all of it stops the browser offering to install.
 *
 * Checks are graded rather than counted. Six of them are *blocking*: fail one and no browser will
 * offer an install, whatever else is right. The rest are the difference between an app that
 * installs and an app that is pleasant, and they are reported as warnings so the six stay visible.
 */
class Preflight extends Component
{
    /** Everything is fetched with this timeout. A slow check is a check nobody runs. */
    private const TIMEOUT = 10;

    private ?Client $client = null;

    /**
     * Runs every check against one site.
     */
    public function run(?Site $site = null, string $trigger = 'manual'): Audit
    {
        $site ??= Craft::$app->getSites()->getPrimarySite();
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $manifest = $plugin->manifests->forSite($site);

        $checks = [];

        if (!$settings->enabled) {
            $checks[] = Check::fail(
                'enabled',
                Check::GROUP_DELIVERY,
                Craft::t('pwa', 'Plugin enabled'),
                Craft::t('pwa', 'PWA is switched off, so nothing is being served.'),
                Craft::t('pwa', 'Turn it on in Settings → General.'),
                true,
            );

            return $this->finish($checks, $site, $trigger);
        }

        $checks = array_merge(
            $checks,
            $this->checkDelivery($site),
            $this->checkManifest($site, $manifest),
            $this->checkIcons($site, $manifest),
            $this->checkWorker($site),
            $this->checkOffline($site),
            $this->checkPush(),
        );

        return $this->finish($checks, $site, $trigger);
    }

    // -------------------------------------------------------------------------- delivery

    /** @return Check[] */
    private function checkDelivery(Site $site): array
    {
        $checks = [];
        $baseUrl = App::parseEnv($site->getBaseUrl()) ?: '';
        $host = (string)parse_url($baseUrl, PHP_URL_HOST);
        $scheme = (string)parse_url($baseUrl, PHP_URL_SCHEME);

        $local = in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.localhost');

        if ($scheme === 'https' || $local) {
            $checks[] = Check::pass(
                'secure-context',
                Check::GROUP_DELIVERY,
                Craft::t('pwa', 'Secure origin'),
                $local
                    ? Craft::t('pwa', 'Served from localhost, which browsers treat as secure.')
                    : Craft::t('pwa', 'Served over HTTPS.'),
                ['baseUrl' => $baseUrl],
            );
        } else {
            $checks[] = Check::fail(
                'secure-context',
                Check::GROUP_DELIVERY,
                Craft::t('pwa', 'Secure origin'),
                Craft::t('pwa', 'The site’s base URL is not HTTPS, so no service worker can register at all.'),
                Craft::t('pwa', 'Serve the site over HTTPS. There is no workaround: a service worker requires a secure context, and everything else here depends on it.'),
                true,
                ['baseUrl' => $baseUrl],
            );
        }

        // Everything below fetches the site, so a site PHP cannot reach itself is worth saying
        // once rather than reporting as nine unrelated failures.
        $home = $this->fetch($baseUrl ?: '/');

        if ($home === null) {
            $checks[] = Check::warn(
                'reachable',
                Check::GROUP_DELIVERY,
                Craft::t('pwa', 'Site reachable'),
                Craft::t('pwa', 'The site could not be fetched from the server, so the checks that need a real response were skipped.'),
                Craft::t('pwa', 'This is usually DNS inside a container, a firewall, or basic auth on a staging site. The site may be perfectly fine for visitors.'),
                ['url' => $baseUrl],
            );

            return $checks;
        }

        $html = $home['body'];

        $manifestLinks = preg_match_all('/<link[^>]+rel=["\']?manifest["\']?[^>]*>/i', $html);

        if ($manifestLinks === 0) {
            $checks[] = Check::fail(
                'manifest-link',
                Check::GROUP_DELIVERY,
                Craft::t('pwa', 'Manifest link in the page'),
                Craft::t('pwa', 'No <link rel="manifest"> was found on the home page.'),
                Craft::t('pwa', 'Turn on Inject tags in Settings → General, or add {{ pwa.head() }} inside your <head>.'),
                true,
            );
        } elseif ($manifestLinks > 1) {
            $checks[] = Check::warn(
                'manifest-link',
                Check::GROUP_DELIVERY,
                Craft::t('pwa', 'Manifest link in the page'),
                Craft::t('pwa', 'The page has {count} manifest links. Browsers use the first and ignore the rest.', ['count' => $manifestLinks]),
                Craft::t('pwa', 'Something else is also adding one — a template, a theme, or another plugin. Remove the duplicate, or turn off Inject tags and place {{ pwa.head() }} yourself.'),
            );
        } else {
            $checks[] = Check::pass(
                'manifest-link',
                Check::GROUP_DELIVERY,
                Craft::t('pwa', 'Manifest link in the page'),
                Craft::t('pwa', 'The home page links to the manifest.'),
            );
        }

        if (preg_match('/<meta[^>]+name=["\']?viewport["\']?/i', $html) === 1) {
            $checks[] = Check::pass(
                'viewport',
                Check::GROUP_DELIVERY,
                Craft::t('pwa', 'Viewport meta tag'),
                Craft::t('pwa', 'The page declares a viewport.'),
            );
        } else {
            $checks[] = Check::fail(
                'viewport',
                Check::GROUP_DELIVERY,
                Craft::t('pwa', 'Viewport meta tag'),
                Craft::t('pwa', 'The page has no viewport meta tag, so it will be rendered at desktop width inside the installed app.'),
                Craft::t('pwa', 'Add <meta name="viewport" content="width=device-width, initial-scale=1"> to your layout. PWA does not inject this one: it changes how every page of the site is laid out, which is not a plugin’s decision to make.'),
            );
        }

        if (Craft::$app->getConfig()->getGeneral()->devMode) {
            $checks[] = Check::warn(
                'dev-mode',
                Check::GROUP_DELIVERY,
                Craft::t('pwa', 'Dev mode'),
                Craft::t('pwa', 'Dev mode is on. A registered service worker will keep serving cached responses while you edit templates.'),
                Craft::t('pwa', 'If a change is not showing up, unregister the worker in your browser’s developer tools (Application → Service workers → Unregister) rather than relying on a hard refresh.'),
            );
        }

        return $checks;
    }

    // -------------------------------------------------------------------------- manifest

    /** @return Check[] */
    private function checkManifest(Site $site, Manifest $manifest): array
    {
        $checks = [];
        $url = Plugin::getInstance()->serviceWorker->manifestUrl($site);
        $response = $this->fetch($url);

        if ($response === null || $response['status'] !== 200) {
            return [Check::fail(
                'manifest-served',
                Check::GROUP_MANIFEST,
                Craft::t('pwa', 'Manifest served'),
                Craft::t('pwa', 'The manifest did not respond with 200 at {url}.', ['url' => $url]),
                Craft::t('pwa', 'Something is intercepting the URL before Craft sees it — usually a rewrite rule for static files, or a `.webmanifest` handler in the web server. Try changing the manifest path in Settings → General.'),
                true,
                ['url' => $url, 'status' => $response['status'] ?? null],
            )];
        }

        $data = Json::decodeIfJson($response['body']);

        if (!is_array($data)) {
            return [Check::fail(
                'manifest-served',
                Check::GROUP_MANIFEST,
                Craft::t('pwa', 'Manifest served'),
                Craft::t('pwa', 'The manifest URL responded, but not with JSON.'),
                Craft::t('pwa', 'Something is rendering a page at that URL instead of the manifest — check for a template or a route with the same path.'),
                true,
                ['url' => $url, 'body' => substr($response['body'], 0, 200)],
            )];
        }

        $checks[] = Check::pass(
            'manifest-served',
            Check::GROUP_MANIFEST,
            Craft::t('pwa', 'Manifest served'),
            Craft::t('pwa', 'Valid JSON, served from {url}.', ['url' => $url]),
            ['url' => $url],
        );

        $type = strtolower($response['contentType']);

        if (!str_contains($type, 'manifest+json') && !str_contains($type, 'application/json')) {
            $checks[] = Check::warn(
                'manifest-type',
                Check::GROUP_MANIFEST,
                Craft::t('pwa', 'Manifest content type'),
                Craft::t('pwa', 'The manifest is served as “{type}”.', ['type' => $response['contentType'] ?: 'nothing']),
                Craft::t('pwa', 'Browsers are forgiving about this, but proxies and CDNs are not. It should be application/manifest+json.'),
            );
        }

        $shortName = (string)($data['short_name'] ?? '');

        if (mb_strlen($shortName) > 12) {
            $checks[] = Check::warn(
                'short-name',
                Check::GROUP_MANIFEST,
                Craft::t('pwa', 'Short name length'),
                Craft::t('pwa', '“{name}” is {length} characters, so it will be truncated under the home screen icon.', ['name' => $shortName, 'length' => mb_strlen($shortName)]),
                Craft::t('pwa', 'Twelve characters or fewer survives on most launchers.'),
            );
        } else {
            $checks[] = Check::pass(
                'short-name',
                Check::GROUP_MANIFEST,
                Craft::t('pwa', 'Short name'),
                Craft::t('pwa', '“{name}” fits under a home screen icon.', ['name' => $shortName]),
            );
        }

        $display = (string)($data['display'] ?? 'browser');

        if ($display === 'browser') {
            $checks[] = Check::fail(
                'display',
                Check::GROUP_MANIFEST,
                Craft::t('pwa', 'Display mode'),
                Craft::t('pwa', 'Display is “browser”, which tells the browser this is a website and not an app.'),
                Craft::t('pwa', 'Set it to standalone in PWA → Manifest. Nothing will offer to install while it is browser.'),
                true,
            );
        } else {
            $checks[] = Check::pass(
                'display',
                Check::GROUP_MANIFEST,
                Craft::t('pwa', 'Display mode'),
                Craft::t('pwa', 'Set to {display}.', ['display' => $display]),
            );
        }

        // The start URL is checked without following redirects on purpose: a start URL that 301s
        // works, but every single launch pays for it, and it is almost always an accident —
        // a trailing slash, or an http URL kept from before the site had a certificate.
        $startUrl = (string)($data['start_url'] ?? '/');
        $startAbsolute = $this->absolute($startUrl, $site);
        $start = $startAbsolute !== null ? $this->fetch($startAbsolute, false) : null;

        if ($startAbsolute === null) {
            $checks[] = Check::fail(
                'start-url',
                Check::GROUP_MANIFEST,
                Craft::t('pwa', 'Start URL'),
                Craft::t('pwa', 'The start URL {url} is on another origin.', ['url' => $startUrl]),
                Craft::t('pwa', 'A manifest’s start URL must be on the same origin as the site, or the browser discards it. Use a path such as /.'),
                true,
            );
        } elseif ($start === null) {
            $checks[] = Check::warn(
                'start-url',
                Check::GROUP_MANIFEST,
                Craft::t('pwa', 'Start URL'),
                Craft::t('pwa', 'The start URL could not be fetched.'),
                Craft::t('pwa', 'Check that {url} loads for a logged-out visitor.', ['url' => $startUrl]),
            );
        } elseif ($start['status'] >= 300 && $start['status'] < 400) {
            $checks[] = Check::warn(
                'start-url',
                Check::GROUP_MANIFEST,
                Craft::t('pwa', 'Start URL'),
                Craft::t('pwa', 'The start URL redirects ({status} → {location}).', ['status' => $start['status'], 'location' => $start['location']]),
                Craft::t('pwa', 'Point start_url at the destination instead. Every launch of the installed app pays for this redirect.'),
                ['status' => $start['status'], 'location' => $start['location']],
            );
        } elseif ($start['status'] !== 200) {
            $checks[] = Check::fail(
                'start-url',
                Check::GROUP_MANIFEST,
                Craft::t('pwa', 'Start URL'),
                Craft::t('pwa', 'The start URL responded {status}.', ['status' => $start['status']]),
                Craft::t('pwa', 'The installed app opens this URL every time it is launched. It has to be a page.'),
                true,
                ['status' => $start['status']],
            );
        } else {
            $checks[] = Check::pass(
                'start-url',
                Check::GROUP_MANIFEST,
                Craft::t('pwa', 'Start URL'),
                Craft::t('pwa', '{url} loads.', ['url' => $startUrl]),
            );
        }

        $scope = (string)($data['scope'] ?? '/');
        $startPath = parse_url($startUrl, PHP_URL_PATH) ?: '/';

        if (!str_starts_with($startPath, $scope)) {
            $checks[] = Check::fail(
                'scope',
                Check::GROUP_MANIFEST,
                Craft::t('pwa', 'Scope covers the start URL'),
                Craft::t('pwa', 'The scope is “{scope}” but the start URL is “{start}”, which is outside it.', ['scope' => $scope, 'start' => $startPath]),
                Craft::t('pwa', 'A start URL outside the scope makes the manifest invalid, and the app will not install.'),
                true,
            );
        } else {
            $checks[] = Check::pass(
                'scope',
                Check::GROUP_MANIFEST,
                Craft::t('pwa', 'Scope'),
                Craft::t('pwa', '“{scope}” covers the start URL.', ['scope' => $scope]),
            );
        }

        foreach (['theme_color' => Craft::t('pwa', 'Theme colour'), 'background_color' => Craft::t('pwa', 'Background colour')] as $key => $label) {
            if (trim((string)($data[$key] ?? '')) === '') {
                $checks[] = Check::warn(
                    'colour-' . $key,
                    Check::GROUP_MANIFEST,
                    $label,
                    Craft::t('pwa', 'Not set, so the OS picks one.'),
                    Craft::t('pwa', 'Set it in PWA → Manifest. The theme colour paints the title bar; the background colour is what shows while the app is starting.'),
                );
            }
        }

        return $checks;
    }

    // ----------------------------------------------------------------------------- icons

    /** @return Check[] */
    private function checkIcons(Site $site, Manifest $manifest): array
    {
        $plugin = Plugin::getInstance();
        $icons = $plugin->icons->manifestIcons($manifest);

        if ($manifest->getSourceAsset() === null) {
            return [Check::fail(
                'icon-source',
                Check::GROUP_ICONS,
                Craft::t('pwa', 'Icon source'),
                Craft::t('pwa', 'No source image has been chosen, so the manifest lists no icons.'),
                Craft::t('pwa', 'Pick a square image of at least 512×512 in PWA → Manifest. Everything else is generated from it.'),
                true,
            )];
        }

        $checks = [];
        $source = $manifest->getSourceAsset();
        $width = (int)$source->getWidth();
        $height = (int)$source->getHeight();

        if ($width < 512 || $height < 512) {
            $checks[] = Check::warn(
                'icon-source',
                Check::GROUP_ICONS,
                Craft::t('pwa', 'Icon source'),
                Craft::t('pwa', 'The source image is {w}×{h}, so the 512px icon is being upscaled.', ['w' => $width, 'h' => $height]),
                Craft::t('pwa', 'Upload one at 512×512 or larger. A blurry home screen icon is the most visible part of the whole install.'),
            );
        } elseif (abs($width - $height) > 2) {
            $checks[] = Check::warn(
                'icon-source',
                Check::GROUP_ICONS,
                Craft::t('pwa', 'Icon source'),
                Craft::t('pwa', 'The source image is {w}×{h}, which is not square, so the generated icons are letterboxed.', ['w' => $width, 'h' => $height]),
                Craft::t('pwa', 'Crop it square before uploading.'),
            );
        } else {
            $checks[] = Check::pass(
                'icon-source',
                Check::GROUP_ICONS,
                Craft::t('pwa', 'Icon source'),
                Craft::t('pwa', '{w}×{h}, square.', ['w' => $width, 'h' => $height]),
            );
        }

        $sizes = array_map(static fn(array $icon) => (string)$icon['sizes'], $icons);
        $missing = array_values(array_diff(['192x192', '512x512'], $sizes));

        if (!empty($missing)) {
            $checks[] = Check::fail(
                'icon-sizes',
                Check::GROUP_ICONS,
                Craft::t('pwa', 'Required icon sizes'),
                Craft::t('pwa', 'The manifest is missing {sizes}.', ['sizes' => implode(' and ', $missing)]),
                Craft::t('pwa', 'Regenerate the icons from the flight deck. If it keeps failing, the web root or storage directory is not writable.'),
                true,
            );
        } else {
            // Listed is not the same as reachable — this is the check that catches an icon
            // directory that was never deployed.
            $iconUrl = $this->absolute((string)$icons[0]['src'], $site);
            $probe = $iconUrl !== null ? $this->fetch($iconUrl) : null;

            if ($probe === null || $probe['status'] !== 200) {
                $checks[] = Check::fail(
                    'icon-sizes',
                    Check::GROUP_ICONS,
                    Craft::t('pwa', 'Icons reachable'),
                    Craft::t('pwa', 'The manifest lists icons, but {url} responded {status}.', ['url' => $icons[0]['src'], 'status' => $probe['status'] ?? '—']),
                    Craft::t('pwa', 'The generated files are not being served. If the web root is read-only on this environment they are in storage/ and need the fallback route, which means the site’s URL rules must reach Craft.'),
                    true,
                );
            } else {
                $checks[] = Check::pass(
                    'icon-sizes',
                    Check::GROUP_ICONS,
                    Craft::t('pwa', 'Icons'),
                    Craft::t('pwa', '{count} icons listed and served.', ['count' => count($icons)]),
                );
            }
        }

        $maskable = array_filter($icons, static fn(array $icon) => ($icon['purpose'] ?? '') === 'maskable');

        if (empty($maskable)) {
            $checks[] = Check::warn(
                'maskable',
                Check::GROUP_ICONS,
                Craft::t('pwa', 'Maskable icon'),
                Craft::t('pwa', 'No maskable icon, so Android will put the icon in a white box.'),
                Craft::t('pwa', 'Regenerate the icons — the maskable pair is produced automatically.'),
            );
        } else {
            $checks[] = Check::pass(
                'maskable',
                Check::GROUP_ICONS,
                Craft::t('pwa', 'Maskable icon'),
                Craft::t('pwa', 'Present, so launchers can crop it to their own shape.'),
            );
        }

        return $checks;
    }

    // ---------------------------------------------------------------------------- worker

    /** @return Check[] */
    private function checkWorker(Site $site): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (!$settings->serviceWorkerEnabled) {
            return [Check::fail(
                'worker-enabled',
                Check::GROUP_WORKER,
                Craft::t('pwa', 'Service worker'),
                Craft::t('pwa', 'The service worker is switched off, so the site cannot be installed and does nothing offline.'),
                Craft::t('pwa', 'Turn it on in Settings → General.'),
                true,
            )];
        }

        $checks = [];
        $url = $plugin->serviceWorker->scriptUrl($site);
        $response = $this->fetch($url);

        if ($response === null || $response['status'] !== 200) {
            return [Check::fail(
                'worker-served',
                Check::GROUP_WORKER,
                Craft::t('pwa', 'Service worker served'),
                Craft::t('pwa', 'The worker did not respond with 200 at {url}.', ['url' => $url]),
                Craft::t('pwa', 'A rewrite rule for .js files is the usual cause: the web server answers before Craft does, and there is no file there. Either exclude the path or rename it in Settings → General.'),
                true,
                ['url' => $url, 'status' => $response['status'] ?? null],
            )];
        }

        if (!str_contains(strtolower($response['contentType']), 'javascript')) {
            $checks[] = Check::fail(
                'worker-type',
                Check::GROUP_WORKER,
                Craft::t('pwa', 'Service worker content type'),
                Craft::t('pwa', 'The worker is served as “{type}”, and browsers refuse to register a worker that is not JavaScript.', ['type' => $response['contentType'] ?: 'nothing']),
                Craft::t('pwa', 'A proxy or CDN is rewriting the content type. It must be text/javascript.'),
                true,
            );
        } else {
            $checks[] = Check::pass(
                'worker-served',
                Check::GROUP_WORKER,
                Craft::t('pwa', 'Service worker served'),
                Craft::t('pwa', 'Served as JavaScript from {url}.', ['url' => $url]),
            );
        }

        // A worker cached for a day is a worker that cannot be updated for a day, and the site is
        // stuck with whatever flight plan was current when it was cached.
        $cacheControl = strtolower($response['cacheControl']);

        if (preg_match('/max-age=(\d+)/', $cacheControl, $m) === 1 && (int)$m[1] > 86400) {
            $checks[] = Check::warn(
                'worker-cache',
                Check::GROUP_WORKER,
                Craft::t('pwa', 'Service worker caching'),
                Craft::t('pwa', 'The worker is served with max-age={age}, so browsers may not check for a new one for that long.', ['age' => $m[1]]),
                Craft::t('pwa', 'Serve it with no-cache. Browsers cap this at 24 hours by spec, but a CDN in front will not.'),
            );
        }

        $path = ltrim($settings->serviceWorkerPath, '/');

        if (str_contains($path, '/')) {
            $checks[] = Check::warn(
                'worker-scope',
                Check::GROUP_WORKER,
                Craft::t('pwa', 'Worker scope'),
                Craft::t('pwa', 'The worker is served from a subdirectory, so it can only control “/{dir}/”.', ['dir' => dirname($path)]),
                Craft::t('pwa', 'Move it to the web root, or make sure the response carries a Service-Worker-Allowed header.'),
            );
        } else {
            $checks[] = Check::pass(
                'worker-scope',
                Check::GROUP_WORKER,
                Craft::t('pwa', 'Worker scope'),
                Craft::t('pwa', 'Served from the root, so it can control the whole site.'),
            );
        }

        $routes = $settings->getRoutes();
        $enabled = array_values(array_filter($routes, static fn(Route $r) => $r->enabled));
        $last = end($enabled) ?: null;

        if ($last === null || !$last->matches('/some/page/that/matches/nothing/specific')) {
            $checks[] = Check::warn(
                'catch-all',
                Check::GROUP_WORKER,
                Craft::t('pwa', 'Flight plan catch-all'),
                Craft::t('pwa', 'No rule matches an ordinary page, so most of the site is not handled by the worker at all.'),
                Craft::t('pwa', 'Add a rule at the bottom matching “*” — usually network first, into the pages cache.'),
            );
        } else {
            $checks[] = Check::pass(
                'catch-all',
                Check::GROUP_WORKER,
                Craft::t('pwa', 'Flight plan'),
                Craft::t('pwa', '{count} rules, ending in a catch-all.', ['count' => count($enabled)]),
            );
        }

        $cpTrigger = trim((string)Craft::$app->getConfig()->getGeneral()->cpTrigger, '/') ?: 'admin';
        $cpRoute = null;

        foreach ($enabled as $route) {
            if ($route->matches('/' . $cpTrigger . '/dashboard')) {
                $cpRoute = $route;
                break;
            }
        }

        if ($cpRoute === null || $cpRoute->strategy !== Route::STRATEGY_NETWORK_ONLY) {
            $checks[] = Check::fail(
                'cp-not-cached',
                Check::GROUP_WORKER,
                Craft::t('pwa', 'Control panel excluded'),
                Craft::t('pwa', 'Control panel requests are being handled by the {strategy} rule instead of going straight to the network.', ['strategy' => $cpRoute?->label ?: Craft::t('pwa', 'catch-all')]),
                Craft::t('pwa', 'Put a network-only rule for “/{trigger}/*” at the top of the flight plan. A cached control panel serves one author’s session shell to the next.', ['trigger' => $cpTrigger]),
            );
        } else {
            $checks[] = Check::pass(
                'cp-not-cached',
                Check::GROUP_WORKER,
                Craft::t('pwa', 'Control panel excluded'),
                Craft::t('pwa', 'Control panel requests always go to the network.'),
            );
        }

        $precache = $plugin->serviceWorker->precache($site);

        if (count($precache) > 30) {
            $checks[] = Check::warn(
                'precache-size',
                Check::GROUP_WORKER,
                Craft::t('pwa', 'Precache size'),
                Craft::t('pwa', '{count} URLs are fetched before the worker activates.', ['count' => count($precache)]),
                Craft::t('pwa', 'Everything here is downloaded on the visitor’s first page view. Precache the shell and the offline page; let everything else arrive as it is used.'),
            );
        } else {
            $checks[] = Check::pass(
                'precache-size',
                Check::GROUP_WORKER,
                Craft::t('pwa', 'Precache'),
                Craft::t('pwa', '{count} URLs.', ['count' => count($precache)]),
            );
        }

        return $checks;
    }

    // --------------------------------------------------------------------------- offline

    /** @return Check[] */
    private function checkOffline(Site $site): array
    {
        $plugin = Plugin::getInstance();
        $url = $plugin->serviceWorker->offlineUrl($site);

        if ($url === null) {
            return [Check::warn(
                'offline-page',
                Check::GROUP_OFFLINE,
                Craft::t('pwa', 'Offline page'),
                Craft::t('pwa', 'No offline page is configured.'),
                Craft::t('pwa', 'Set one in Settings → Offline. Without it, a page that was never visited shows the browser’s own error.'),
            )];
        }

        $response = $this->fetch($url);

        if ($response === null || $response['status'] !== 200) {
            return [Check::fail(
                'offline-page',
                Check::GROUP_OFFLINE,
                Craft::t('pwa', 'Offline page'),
                Craft::t('pwa', 'The offline page responded {status} at {url}.', ['status' => $response['status'] ?? '—', 'url' => $url]),
                Craft::t('pwa', 'It is fetched when the worker installs, so a broken one means visitors get nothing at all when they go offline.'),
            )];
        }

        $checks = [Check::pass(
            'offline-page',
            Check::GROUP_OFFLINE,
            Craft::t('pwa', 'Offline page'),
            Craft::t('pwa', 'Serves 200 from {url}.', ['url' => $url]),
        )];

        if (!in_array($url, $plugin->serviceWorker->precache($site), true)) {
            $checks[] = Check::fail(
                'offline-precached',
                Check::GROUP_OFFLINE,
                Craft::t('pwa', 'Offline page precached'),
                Craft::t('pwa', 'The offline page is not in the precache list, so it will not be there when it is needed.'),
                Craft::t('pwa', 'Turn on “Precache the essentials” in Settings → Offline, or add the page to the precache list yourself.'),
            );
        } else {
            $checks[] = Check::pass(
                'offline-precached',
                Check::GROUP_OFFLINE,
                Craft::t('pwa', 'Offline page precached'),
                Craft::t('pwa', 'Stored when the worker installs, before it is ever needed.'),
            );
        }

        return $checks;
    }

    // ------------------------------------------------------------------------------ push

    /** @return Check[] */
    private function checkPush(): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (!$plugin->isPro()) {
            return [Check::skip(
                'push',
                Check::GROUP_PUSH,
                Craft::t('pwa', 'Push notifications'),
                Craft::t('pwa', 'Push is a Pro feature and is not checked on this edition.'),
            )];
        }

        if (!$settings->pushEnabled) {
            return [Check::skip(
                'push',
                Check::GROUP_PUSH,
                Craft::t('pwa', 'Push notifications'),
                Craft::t('pwa', 'Push is switched off.'),
            )];
        }

        $checks = [];

        try {
            $keys = $plugin->push->getKeys();
            $usable = $keys['publicKey'] !== '' && \justinholtweb\pwa\push\Encryptor::isUsableKey($keys['privateKey']);
        } catch (Throwable $e) {
            $usable = false;
        }

        if (!$usable) {
            $checks[] = Check::fail(
                'vapid',
                Check::GROUP_PUSH,
                Craft::t('pwa', 'VAPID keypair'),
                Craft::t('pwa', 'The keypair is missing or unreadable, so nothing can be sent.'),
                Craft::t('pwa', 'Generate one in Settings → Push. If the keys come from environment variables, check that both are set and that the private key matches the public one.'),
            );
        } else {
            $checks[] = Check::pass(
                'vapid',
                Check::GROUP_PUSH,
                Craft::t('pwa', 'VAPID keypair'),
                Craft::t('pwa', 'Present and usable.'),
            );
        }

        if (trim((string)App::parseEnv($settings->pushSubject)) === '') {
            $checks[] = Check::warn(
                'push-subject',
                Check::GROUP_PUSH,
                Craft::t('pwa', 'VAPID subject'),
                Craft::t('pwa', 'No contact address is configured, so one is guessed from the system email.'),
                Craft::t('pwa', 'Set a mailto: address in Settings → Push. Some push services reject messages without one.'),
            );
        }

        return $checks;
    }

    // ------------------------------------------------------------------------- persistence

    public function save(Audit $audit): bool
    {
        $record = new AuditRecord();
        $record->siteId = $audit->siteId;
        $record->trigger = $audit->trigger;
        $record->score = $audit->score;
        $record->installable = $audit->installable;
        $record->passed = $audit->passed;
        $record->warned = $audit->warned;
        $record->failed = $audit->failed;
        $record->skipped = $audit->skipped;
        $record->checks = Json::encode(array_map(static fn(Check $c) => $c->toArray(), $audit->checks));

        if (!$record->save()) {
            Plugin::error('Could not save an audit: ' . Json::encode($record->getErrors()));
            return false;
        }

        $audit->id = (int)$record->id;

        return true;
    }

    public function getLatest(?int $siteId = null): ?Audit
    {
        $query = AuditRecord::find()->orderBy(['dateCreated' => SORT_DESC]);

        if ($siteId !== null) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $record = $query->one();

        return $record ? $this->toModel($record) : null;
    }

    /** @return Audit[] */
    public function getAudits(?int $siteId = null, int $limit = 50): array
    {
        $query = AuditRecord::find()->orderBy(['dateCreated' => SORT_DESC])->limit($limit);

        if ($siteId !== null) {
            $query->andWhere(['siteId' => $siteId]);
        }

        return array_map(fn(AuditRecord $r) => $this->toModel($r), $query->all());
    }

    /**
     * When the scheduled check last ran, whatever has run since.
     *
     * Asked of the database directly: reading it off the most recent handful of audits misses it
     * as soon as twenty manual and console runs have happened in between, and the schedule then
     * fires on every hourly check until one more scheduled run pushes it back into view.
     */
    public function getLastScheduledAt(): ?DateTime
    {
        $value = (new Query())
            ->from(AuditRecord::tableName())
            ->where(['trigger' => 'scheduled'])
            ->max('[[dateCreated]]');

        return $value ? (DateTimeHelper::toDateTime($value) ?: null) : null;
    }

    public function getAuditById(int $id): ?Audit
    {
        $record = AuditRecord::findOne($id);

        return $record ? $this->toModel($record) : null;
    }

    public function prune(): int
    {
        $days = Plugin::getInstance()->getSettings()->auditRetentionDays;

        if ($days <= 0) {
            return 0;
        }

        return AuditRecord::deleteAll(['<', 'dateCreated', Db::prepareDateForDb((new DateTime())->modify("-{$days} days"))]);
    }

    // --------------------------------------------------------------------------- plumbing

    /**
     * @param Check[] $checks
     */
    private function finish(array $checks, Site $site, string $trigger): Audit
    {
        $audit = Audit::fromChecks($checks, $site->id, $trigger);
        $audit->dateCreated = new DateTime();

        return $audit;
    }

    /**
     * One HTTP request, or null if it could not be made at all.
     *
     * `http_errors` is off because a 404 is an answer, not an exception — most of what this class
     * looks for *is* a non-200.
     *
     * @return array{status: int, body: string, contentType: string, cacheControl: string, location: string}|null
     */
    private function fetch(string $url, bool $followRedirects = true): ?array
    {
        try {
            $response = $this->client()->request('GET', $url, [
                'http_errors' => false,
                'allow_redirects' => $followRedirects ? ['max' => 3] : false,
            ]);

            return [
                'status' => $response->getStatusCode(),
                'body' => (string)$response->getBody(),
                'contentType' => $response->getHeaderLine('Content-Type'),
                'cacheControl' => $response->getHeaderLine('Cache-Control'),
                'location' => $response->getHeaderLine('Location'),
            ];
        } catch (Throwable $e) {
            Plugin::info('Preflight could not fetch ' . $url . ': ' . $e->getMessage());
            return null;
        }
    }

    private function client(): Client
    {
        return $this->client ??= Craft::createGuzzleClient([
            'timeout' => self::TIMEOUT,
            'connect_timeout' => 5,
            'headers' => [
                // Announced honestly. A check that pretends to be Chrome gets Chrome's version of
                // the page, which is not the thing being checked.
                'User-Agent' => 'Craft-PWA-Preflight/1.0 (+https://justinholt.com/plugins/craft-pwa)',
                'Accept' => '*/*',
            ],
            'verify' => !Craft::$app->getConfig()->getGeneral()->devMode,
        ]);
    }

    /**
     * A URL from the manifest, made absolute against the site — or null if it points at another
     * origin.
     *
     * The manifest is the one input here that came from a form, and the checks fetch whatever it
     * says from the server. Refusing anything off-site keeps "run preflight" from being a way to
     * make the server request an arbitrary URL.
     */
    private function absolute(string $url, Site $site): ?string
    {
        $base = (string)App::parseEnv($site->getBaseUrl());

        if (str_contains($url, '://') || str_starts_with($url, '//')) {
            return \justinholtweb\pwa\models\Manifest::isSameOrigin($url, $base) ? $url : null;
        }

        return rtrim($base, '/') . '/' . ltrim($url, '/');
    }

    private function toModel(AuditRecord $record): Audit
    {
        $checks = $record->checks;
        $decoded = is_string($checks) ? (array)Json::decodeIfJson($checks) : (array)($checks ?? []);

        return new Audit([
            'id' => (int)$record->id,
            'siteId' => $record->siteId !== null ? (int)$record->siteId : null,
            'trigger' => (string)$record->trigger,
            'score' => (int)$record->score,
            'installable' => (bool)$record->installable,
            'passed' => (int)$record->passed,
            'warned' => (int)$record->warned,
            'failed' => (int)$record->failed,
            'skipped' => (int)$record->skipped,
            'checks' => array_map(static fn(array $c) => new Check($c), $decoded),
            'dateCreated' => $record->dateCreated ? (DateTimeHelper::toDateTime($record->dateCreated) ?: null) : null,
        ]);
    }
}
