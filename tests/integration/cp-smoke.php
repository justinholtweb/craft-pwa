<?php
/**
 * Every PWA control panel screen, rendered over HTTP as an admin, plus the saves that used to go
 * wrong — checked in the shared plugin-testing harness.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-pwa/tests/integration/cp-smoke.php
 *     … cp-smoke.php --read-only
 *
 * `--read-only` expects the harness to turn allowAdminChanges off for requests carrying an
 * `X-Pwa-Read-Only-Test` header — a temporary line at the top of its config/general.php, so the
 * other suites sharing the install are not affected. The harness sets CRAFT_ALLOW_ADMIN_CHANGES in
 * .env, and an environment variable beats the config file, so the line overrides that:
 *
 *     if (!empty($_SERVER['HTTP_X_PWA_READ_ONLY_TEST'])) { $_SERVER['CRAFT_ALLOW_ADMIN_CHANGES'] = 'false'; }
 *
 * Restores the manifest and settings it changes, and removes the template it writes.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\db\Query;
use craft\elements\User;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use justinholtweb\pwa\Plugin;

$readOnly = in_array('--read-only', $argv, true);
$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage();
    }

    if ($result === true) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    }
}

/**
 * Retries a request that failed on the harness rather than on the plugin.
 *
 * Other suites share this install and write project config while this runs, so a request can die
 * with a stale-config exception, or with an unwritable YAML file at the end of the request, that
 * has nothing to do with PWA. Those are 5xx; anything the plugin answers itself is not retried.
 */
function pwaSmokeRetry(callable $request): Psr\Http\Message\ResponseInterface
{
    for ($attempt = 1; ; $attempt++) {
        $response = $request();

        if ($response->getStatusCode() < 500 || $attempt === 4) {
            return $response;
        }

        sleep(3);
    }
}

/** The part of an error page worth printing: its message, not its stylesheet. */
function pwaSmokeMessage(string $html): string
{
    $html = preg_replace('#<(style|script)\b.*?</\1>#s', '', $html) ?? $html;

    return substr(trim((string)preg_replace('/\s+/', ' ', strip_tags($html))), 0, 300);
}

Craft::$app->getPlugins()->loadPlugins();

$plugin = Plugin::getInstance();
$projectConfig = Craft::$app->getProjectConfig();
$settingsBefore = $projectConfig->get('plugins.pwa.settings');
$run = substr(bin2hex(random_bytes(3)), 0, 6);
$templatesDir = Craft::$app->getPath()->getSiteTemplatesPath();
$headTemplate = "pwa-smoke-head-$run";

register_shutdown_function(function() use ($projectConfig, $settingsBefore, $templatesDir, $headTemplate) {
    @unlink("$templatesDir/$headTemplate.twig");

    Craft::$app->getInfo()->configVersion = (string)(new Query())->select('configVersion')->from('{{%info}}')->scalar();
    $projectConfig->reset();

    if ($projectConfig->get('plugins.pwa.settings') !== $settingsBefore) {
        $projectConfig->set('plugins.pwa.settings', $settingsBefore, 'Restore PWA settings after cp-smoke.php');
        $projectConfig->saveModifiedConfigData();
        $projectConfig->writeYamlFiles(true);
    }
});

// The harness admin's password stops validating every so often; Craft's own save path fixes it.
$admin = User::find()->admin()->status(null)->orderBy(['id' => SORT_ASC])->one();
$admin->newPassword = 'claudepassword';
Craft::$app->getElements()->saveElement($admin, false);
Craft::$app->getDb()->createCommand()->update('{{%users}}', ['locked' => false, 'invalidLoginCount' => null, 'lockoutDate' => null], ['id' => $admin->id])->execute();

$jar = new CookieJar();
// With --read-only the harness is expected to turn allowAdminChanges off for requests carrying
// this header (a temporary line in its config/general.php), so nobody else's requests change.
$http = new Client([
    'base_uri' => 'http://127.0.0.1/',
    'cookies' => $jar,
    'http_errors' => false,
    'allow_redirects' => false,
    'headers' => $readOnly ? ['X-Pwa-Read-Only-Test' => '1'] : [],
]);
$token = static function(string $html): ?string {
    return preg_match('/"csrfTokenValue":"([^"]+)"/', $html, $m) ? stripcslashes($m[1]) : null;
};

$login = $http->get('admin/login');
$csrf = $token((string)$login->getBody());
$response = $http->post('admin/actions/users/login', [
    'headers' => ['Accept' => 'application/json'],
    'form_params' => ['loginName' => $admin->username, 'password' => 'claudepassword', 'CRAFT_CSRF_TOKEN' => $csrf],
]);

if ($response->getStatusCode() !== 200) {
    echo "Could not log in: " . $response->getStatusCode() . ' ' . $response->getBody() . "\n";
    exit(1);
}

echo "\nControl panel screens" . ($readOnly ? ' (allowAdminChanges off)' : '') . "\n";

$pages = [
    'admin/pwa',
    'admin/pwa/preflight',
    'admin/pwa/manifest',
    'admin/pwa/flight-plan',
    'admin/pwa/settings',
    'admin/pwa/settings/general',
    'admin/pwa/settings/offline',
    'admin/pwa/settings/prompt',
    'admin/pwa/settings/push',
];

if ($plugin->isPro()) {
    $pages[] = 'admin/pwa/broadcast';
    $pages[] = 'admin/pwa/broadcast/new';
    $pages[] = 'admin/pwa/broadcast/subscribers';
}

$latest = $plugin->preflight->getLatest();

if ($latest !== null) {
    $pages[] = 'admin/pwa/preflight/' . $latest->id;
}

$bodies = [];

foreach ($pages as $page) {
    check("$page answers 200", function() use ($http, $page, &$bodies) {
        $response = pwaSmokeRetry(fn() => $http->get($page));
        $bodies[$page] = (string)$response->getBody();

        return $response->getStatusCode() === 200 ?: $response->getStatusCode() . ' ' . pwaSmokeMessage($bodies[$page]);
    });
}

check('the CP templates are not directly routable', function() use ($http) {
    $status = $http->get('admin/pwa/_deck/index')->getStatusCode();

    return $status === 404 ?: "status $status";
});

check('no PWA screen has a nested form inside its page form', function() use ($bodies) {
    foreach ($bodies as $page => $html) {
        // Only full-page-form screens can nest a form; a screen without one may have several.
        $start = strpos($html, 'id="main-form"');

        if ($start === false) {
            continue;
        }

        $end = strpos($html, '</main>', $start);
        $region = substr($html, $start, $end === false ? null : $end - $start);

        if (substr_count($region, 'name="action"') > 1 || substr_count($region, '<form') > 0) {
            return "$page has " . substr_count($region, 'name="action"') . ' action inputs and ' . substr_count($region, '<form') . ' nested forms';
        }
    }

    return true;
});

if ($readOnly) {
    check('read-only screens say so and cannot be saved', function() use ($bodies) {
        foreach (['admin/pwa/manifest', 'admin/pwa/settings/general'] as $page) {
            $html = $bodies[$page] ?? '';

            if (!str_contains($html, 'admin changes are not allowed on this environment')) {
                return "$page has no read-only notice";
            }

            if (str_contains($html, 'value="pwa/manifest/save"') || str_contains($html, 'value="pwa/settings/save"')) {
                return "$page still posts a save action";
            }

            if (!preg_match('/<input[^>]+(?:id="name"|id="manifestPath")[^>]+disabled/', $html)) {
                return "$page fields are not disabled";
            }
        }

        return true;
    });

    check('invalidating caches still works (it is a database row, not project config)', function() use ($http, $token, $bodies, $plugin) {
        $before = $plugin->serviceWorker->counter(true);
        $response = $http->post('admin/actions/pwa/deck/invalidate', [
            'headers' => ['Accept' => 'application/json', 'X-CSRF-Token' => $token($bodies['admin/pwa'])],
        ]);

        return $response->getStatusCode() === 200 && $plugin->serviceWorker->counter(true) === $before + 1
            ?: $response->getStatusCode() . ' ' . $response->getBody();
    });
} else {
    echo "\nSaves\n";

    check('a manifest saves a colour posted without its # (as Craft’s colour field sends it)', function() use ($http, $token, $bodies, $projectConfig) {
        $site = Craft::$app->getSites()->getPrimarySite();
        $response = pwaSmokeRetry(fn() => $http->post('admin/pwa/manifest?siteHandle=' . $site->handle, [
            'form_params' => [
                'CRAFT_CSRF_TOKEN' => $token((string)$http->get('admin/pwa/manifest?siteHandle=' . $site->handle)->getBody()),
                'action' => 'pwa/manifest/save',
                'siteUid' => $site->uid,
                'name' => 'Smoke test app',
                'themeColor' => 'aa3311',
                'backgroundColor' => 'fafafa',
                'display' => 'standalone',
                'orientation' => 'any',
                'startUrl' => '/',
            ],
        ]));

        // Read back from what browsers are served, which is what the colour is for.
        $served = json_decode((string)$http->get(ltrim(Plugin::getInstance()->getSettings()->manifestPath, '/'))->getBody(), true);
        $stored = $served['theme_color'] ?? null;

        return in_array($response->getStatusCode(), [302, 303], true) && $stored === '#aa3311'
            ?: $response->getStatusCode() . ' → ' . $response->getHeaderLine('Location') . ' served ' . var_export($stored, true) . ' ' . pwaSmokeMessage((string)$response->getBody());
    });

    check('a manifest whose start URL is on another origin is refused and re-rendered with the error', function() use ($http, $token) {
        $site = Craft::$app->getSites()->getPrimarySite();
        $html = $http->get('admin/pwa/manifest?siteHandle=' . $site->handle)->getBody();
        $response = $http->post('admin/pwa/manifest?siteHandle=' . $site->handle, [
            'form_params' => [
                'CRAFT_CSRF_TOKEN' => $token((string)$html),
                'action' => 'pwa/manifest/save',
                'siteUid' => $site->uid,
                'name' => 'Posted name survives',
                'startUrl' => 'https://evil.example/',
                'display' => 'standalone',
                'orientation' => 'any',
            ],
        ]);
        $body = (string)$response->getBody();

        return $response->getStatusCode() === 200 && str_contains($body, 'Posted name survives') && str_contains($body, 'rather than a URL on another origin')
            ?: $response->getStatusCode() . ' ' . pwaSmokeMessage($body);
    });

    check('saving a settings section ignores keys that belong to no section', function() use ($http, $token, $projectConfig) {
        $value = static fn(string $path) => (new Query())->select('value')->from('{{%projectconfig}}')->where(['path' => "plugins.pwa.settings.$path"])->scalar();
        $hostsBefore = $value('extraPushHosts');
        $routesBefore = $value('routes');
        $html = $http->get('admin/pwa/settings/prompt')->getBody();
        $response = $http->post('admin/pwa/settings/prompt', [
            'form_params' => [
                'CRAFT_CSRF_TOKEN' => $token((string)$html),
                'action' => 'pwa/settings/save',
                'section' => 'prompt',
                'settings' => [
                    'promptTitle' => 'Smoke title',
                    'extraPushHosts' => ['internal.example'],
                    'routes' => [['pattern' => '*', 'strategy' => 'cacheOnly']],
                    'pushEnabled' => '1',
                ],
            ],
        ]);

        return in_array($response->getStatusCode(), [302, 303], true)
            && trim((string)$value('promptTitle'), '"') === 'Smoke title'
            && $value('extraPushHosts') === $hostsBefore
            && $value('routes') === $routesBefore
            ?: $response->getStatusCode() . ' extraPushHosts=' . var_export($value('extraPushHosts'), true) . ' routes=' . var_export($value('routes'), true);
    });

    check('an invalid flight plan rule is re-rendered with what was posted', function() use ($http, $token, $plugin) {
        if (!$plugin->isPro()) {
            return true;
        }

        $html = $http->get('admin/pwa/flight-plan')->getBody();
        $response = $http->post('admin/pwa/flight-plan', [
            'form_params' => [
                'CRAFT_CSRF_TOKEN' => $token((string)$html),
                'action' => 'pwa/flight-plan/save',
                'routes' => [
                    ['label' => 'Kept label', 'match' => 'path', 'pattern' => '/kept/*', 'strategy' => 'networkFirst', 'cache' => 'pages', 'networkTimeout' => 3, 'enabled' => 1],
                    ['label' => 'Too wild', 'match' => 'path', 'pattern' => str_repeat('*a', 9), 'strategy' => 'networkFirst', 'cache' => 'pages', 'networkTimeout' => 3, 'enabled' => 1],
                ],
            ],
        ]);
        $body = (string)$response->getBody();

        return $response->getStatusCode() === 200 && str_contains($body, 'Kept label') && str_contains($body, 'wildcards')
            ?: $response->getStatusCode() . ' ' . pwaSmokeMessage($body);
    });
}

echo "\nFront end\n";

check('{{ pwa.head() }} renders the tags in a site template, once', function() use ($http, $templatesDir, $headTemplate) {
    file_put_contents("$templatesDir/$headTemplate.twig", '<!doctype html><html><head><title>t</title>{{ pwa.head() }}</head><body>x</body></html>');
    $html = (string)$http->get("index.php?p=$headTemplate")->getBody();
    $links = preg_match_all('/<link[^>]+rel=["\']?manifest/i', $html);

    return $links === 1 ?: "$links manifest links: " . substr($html, 0, 300);
});

check('the manifest is served', function() use ($http, $plugin) {
    $response = $http->get(ltrim($plugin->getSettings()->manifestPath, '/'));

    return $response->getStatusCode() === 200 && is_array(json_decode((string)$response->getBody(), true)) ?: (string)$response->getStatusCode();
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
