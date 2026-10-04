<?php
/**
 * PWA's anonymous endpoints and its head injection, checked in the shared plugin-testing harness.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-pwa/tests/integration/security.php
 *
 * Push subscriptions arrive anonymously and without CSRF, and every broadcast POSTs to the
 * endpoint they carry — so until the allow-list, a "subscription" could point the server at an
 * internal host and the delivery log kept what it answered. The head injection decorated every
 * HTML response, including documents other plugins serve into frames. Each refusal is paired with
 * the request that should still work.
 *
 * Turns push on for the run (it is off by default) and restores the settings, subscriptions,
 * templates and project config it touched.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\db\Query;
use craft\elements\Asset;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use justinholtweb\pwa\controllers\EventsController;
use justinholtweb\pwa\controllers\PushController;
use justinholtweb\pwa\models\Campaign;
use justinholtweb\pwa\Plugin;
use justinholtweb\pwa\records\SubscriberRecord;
use justinholtweb\pwa\services\Push;

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

Craft::$app->getPlugins()->loadPlugins();

$plugin = Plugin::getInstance();
$run = substr(bin2hex(random_bytes(3)), 0, 6);
$projectConfig = Craft::$app->getProjectConfig();
$settingsBefore = $projectConfig->get('plugins.pwa.settings');
$templatesDir = Craft::$app->getPath()->getSiteTemplatesPath();
$templates = [
    "pwa-security-page-$run" => "<!doctype html><html><head><title>Page</title></head><body>page</body></html>",
    "pwa-security-sandboxed-$run" => "{% header \"Content-Security-Policy: sandbox allow-scripts\" %}<!doctype html><html><head><title>Framed</title></head><body>framed</body></html>",
];
$endpointPrefix = "https://fcm.googleapis.com/fcm/send/pwa-security-$run-";

register_shutdown_function(function() use ($templates, $templatesDir, $settingsBefore, $projectConfig, $run) {
    foreach (array_keys($templates) as $name) {
        @unlink("$templatesDir/$name.twig");
    }

    // `like` wraps the value in % itself; passing false would match only the exact string.
    SubscriberRecord::deleteAll(['like', 'endpoint', "pwa-security-$run"]);
    SubscriberRecord::deleteAll(['endpoint' => 'https://10.0.0.5/admin']);

    // The web process saved nothing; this process changed the settings. Put them back exactly.
    Craft::$app->getInfo()->configVersion = (string)(new Query())->select('configVersion')->from('{{%info}}')->scalar();
    $projectConfig->reset();
    if ($projectConfig->get('plugins.pwa.settings') !== $settingsBefore) {
        $projectConfig->set('plugins.pwa.settings', $settingsBefore, 'Restore PWA settings after security.php');
        $projectConfig->saveModifiedConfigData();
        $projectConfig->writeYamlFiles(true);
    }
});

foreach ($templates as $name => $body) {
    file_put_contents("$templatesDir/$name.twig", $body);
}

$keys = ['p256dh' => 'BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSnfckjBJuBkr3qBUYIHBQFLXYp5Nksh8U', 'auth' => 'tBHItJI5svbpez7KI4CCXg'];

// A previous run in the same minute spends this address's budgets (the burst checks do so on
// purpose); start each run with them full, for every address the requests might come from.
foreach (['push', 'events'] as $bucket) {
    foreach (['127.0.0.1', '::1', gethostbyname(gethostname())] as $ip) {
        Craft::$app->getCache()->delete(sprintf('pwa:rate:%s:%s:%d', $bucket, sha1(justinholtweb\pwa\helpers\RateLimit::key($ip)), intdiv(time(), 60)));
    }
    Craft::$app->getCache()->delete(sprintf('pwa:rate:%s:*:%d', $bucket, intdiv(time(), 60)));
}

$http = new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);
$subscribe = static fn(string $endpoint, array $topics = []) => $http->post('index.php?p=actions/pwa/push/subscribe', [
    'headers' => ['Accept' => 'application/json'],
    'form_params' => ['subscription' => ['endpoint' => $endpoint, 'keys' => $keys], 'topics' => $topics],
]);
$rowFor = static fn(string $endpoint) => SubscriberRecord::findOne(['endpointHash' => hash('sha256', $endpoint)]);

// -------------------------------------------------------------------------------------------
echo "\nPush endpoints\n";

foreach ([
    'Chrome (FCM)' => 'https://fcm.googleapis.com/fcm/send/abc',
    'Firefox' => 'https://updates.push.services.mozilla.com/wpush/v2/abc',
    'Safari' => 'https://web.push.apple.com/abc',
    'Edge (WNS)' => 'https://wns2-par02p.notify.windows.com/w/?token=abc',
] as $label => $endpoint) {
    check("accepts $label", fn() => Push::isPushEndpoint($endpoint) ?: 'refused');
}

foreach ([
    'an internal address' => 'https://10.0.0.5/admin',
    'cloud metadata' => 'https://169.254.169.254/latest/meta-data/',
    'an arbitrary host' => 'https://attacker.example/redirect',
    'a look-alike host' => 'https://fcm.googleapis.com.attacker.example/x',
    'a suffix look-alike' => 'https://evilpush.apple.com/x',
    'plain http' => 'http://fcm.googleapis.com/fcm/send/abc',
    'another port' => 'https://fcm.googleapis.com:8443/x',
    'credentials in the URL' => 'https://user:pass@fcm.googleapis.com/x',
] as $label => $endpoint) {
    check("refuses $label", fn() => Push::isPushEndpoint($endpoint) === false ?: 'accepted');
}

check('extraPushHosts adds a host from config/pwa.php', function() use ($plugin) {
    $plugin->getSettings()->extraPushHosts = ['*.push.example.net'];

    try {
        return Push::isPushEndpoint('https://eu.push.example.net/x') && !Push::isPushEndpoint('https://push.example.net.evil/x') ?: 'not honoured';
    } finally {
        $plugin->getSettings()->extraPushHosts = [];
    }
});

check('topics are kept to short names and capped', function() {
    $topics = Push::cleanTopics(array_merge(['news', 'news', ['nested'], str_repeat('x', 65), '<script>'], array_map(fn($i) => "t$i", range(1, 40))));

    return $topics[0] === 'news' && count($topics) === Push::MAX_TOPICS && !in_array('<script>', $topics, true) ?: json_encode($topics);
});

check('a stored subscription to an internal host is removed at send time, not posted to', function() use ($plugin, $keys) {
    $endpoint = 'https://10.0.0.5/admin';
    $record = new SubscriberRecord([
        'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
        'endpoint' => $endpoint,
        'endpointHash' => hash('sha256', $endpoint),
        'p256dh' => $keys['p256dh'],
        'auth' => $keys['auth'],
        'contentEncoding' => 'aes128gcm',
        'topics' => '[]',
        'failures' => 0,
    ]);
    $record->save(false);
    $subscriber = $plugin->push->getSubscribers(null, [], 0, null);
    $subscriber = array_values(array_filter($subscriber, fn($s) => $s->endpoint === $endpoint))[0] ?? null;

    if ($subscriber === null) {
        return 'fixture row not readable';
    }

    $started = microtime(true);
    [$outcome] = $plugin->push->send($subscriber, new Campaign(['title' => 'Test', 'body' => 'Test']));
    $elapsed = microtime(true) - $started;

    return $outcome === 'gone' && SubscriberRecord::findOne(['endpointHash' => hash('sha256', $endpoint)]) === null && $elapsed < 1
        ?: "outcome $outcome, " . round($elapsed, 2) . 's';
});

// -------------------------------------------------------------------------------------------
echo "\nThe anonymous endpoints, over HTTP\n";

// Push is off by default: switch it on for the web process.
$projectConfig->set('plugins.pwa.settings.pushEnabled', true);
$projectConfig->saveModifiedConfigData();

check('a subscription to an internal host is refused and stored nowhere', function() use ($subscribe, $rowFor) {
    $response = $subscribe('https://10.0.0.5/admin');
    $body = json_decode((string)$response->getBody(), true);

    return ($body['subscribed'] ?? null) === false && $rowFor('https://10.0.0.5/admin') === null ?: $response->getStatusCode() . ' ' . $response->getBody();
});

check('a real push-service subscription is stored, with its topics cleaned', function() use ($subscribe, $rowFor, $endpointPrefix) {
    $endpoint = $endpointPrefix . 'real';
    $body = json_decode((string)$subscribe($endpoint, ['news', '<b>bad</b>'])->getBody(), true);
    $row = $rowFor($endpoint);

    return ($body['subscribed'] ?? null) === true && $row !== null && $row->topics === '["news"]' ?: json_encode([$body, $row?->topics]);
});

/**
 * Sends a burst at the start of a fresh clock minute, all at once.
 *
 * The budgets are per clock minute, and a request to the harness takes seconds — sent one after
 * another, a burst straddles a minute boundary and lands in two budgets, neither of them full.
 *
 * @param callable(int): GuzzleHttp\Promise\PromiseInterface $send
 * @return int[]
 */
function burst(int $count, callable $send): array
{
    sleep(61 - (time() % 60));

    $promises = [];

    for ($i = 0; $i < $count; $i++) {
        $promises[$i] = $send($i);
    }

    return array_map(
        static fn(array $result) => $result['state'] === 'fulfilled' ? $result['value']->getStatusCode() : 0,
        GuzzleHttp\Promise\Utils::settle($promises)->wait(),
    );
}

check('subscribing is rate limited per address', function() use ($http, $keys, $endpointPrefix) {
    $statuses = burst(PushController::PER_MINUTE + 1, fn(int $i) => $http->postAsync('index.php?p=actions/pwa/push/subscribe', [
        'headers' => ['Accept' => 'application/json'],
        'form_params' => ['subscription' => ['endpoint' => $endpointPrefix . "burst-$i", 'keys' => $keys]],
    ]));

    return in_array(429, $statuses, true) && in_array(200, $statuses, true) ?: implode(',', $statuses);
});

check('recording events is rate limited per address', function() use ($http) {
    $statuses = burst(EventsController::PER_MINUTE + 1, fn(int $i) => $http->postAsync('index.php?p=actions/pwa/events/record', [
        'headers' => ['Accept' => 'application/json'],
        'form_params' => ['type' => 'offline', 'path' => '/'],
    ]));

    return in_array(429, $statuses, true) && in_array(200, $statuses, true) ?: implode(',', array_unique($statuses));
});

check('many addresses together still hit one ceiling for the site', function() use ($http) {
    // Stand in for a crowd of addresses: fill the site-wide budget for events, clear this
    // address's own, and a request that is well within its own budget is still refused.
    $minute = intdiv(time(), 60);
    $global = sprintf('pwa:rate:events:*:%d', $minute);
    $cache = Craft::$app->getCache();
    foreach (['127.0.0.1', '::1', gethostbyname(gethostname())] as $ip) {
        $cache->delete(sprintf('pwa:rate:events:%s:%d', sha1(justinholtweb\pwa\helpers\RateLimit::key($ip)), $minute));
    }
    $cache->set($global, EventsController::PER_MINUTE * justinholtweb\pwa\helpers\RateLimit::GLOBAL_FACTOR, 120);
    $post = fn() => $http->post('index.php?p=actions/pwa/events/record', ['headers' => ['Accept' => 'application/json'], 'form_params' => ['type' => 'offline', 'path' => '/']])->getStatusCode();

    $full = $post();
    $cache->delete($global);
    foreach (['127.0.0.1', '::1', gethostbyname(gethostname())] as $ip) {
        $cache->delete(sprintf('pwa:rate:events:%s:%d', sha1(justinholtweb\pwa\helpers\RateLimit::key($ip)), $minute));
    }
    $clear = $post();

    return $full === 429 && $clear === 200 ?: "with the ceiling full $full, after clearing $clear";
});

// -------------------------------------------------------------------------------------------
echo "\nHead injection\n";

$hasPwaTags = static fn(string $html) => (bool)preg_match('/<link[^>]+rel=["\']?manifest/i', $html);

check('a page the site renders gets the PWA tags', function() use ($http, $run, $hasPwaTags) {
    return $hasPwaTags((string)$http->get("index.php?p=pwa-security-page-$run")->getBody()) ?: 'no manifest link';
});

check('a document served with a sandbox CSP (a frame, like Eye’s proxy) does not', function() use ($http, $run, $hasPwaTags) {
    $response = $http->get("index.php?p=pwa-security-sandboxed-$run");

    return $response->getStatusCode() === 200 && !$hasPwaTags((string)$response->getBody()) ?: 'tags injected';
});

check('a page answered by a controller action does not', function() use ($http, $hasPwaTags) {
    $response = $http->get('index.php?p=actions/pwa/worker/offline');

    return $response->getStatusCode() === 200 && !$hasPwaTags((string)$response->getBody()) ?: 'status ' . $response->getStatusCode() . ', tags injected';
});

// -------------------------------------------------------------------------------------------
echo "\nManifest assets\n";

check('a manifest stores its icon by UID, and resolves it back', function() use ($plugin) {
    $asset = Asset::find()->kind('image')->one();

    if ($asset === null) {
        return 'needs an image asset in the harness';
    }

    $siteUid = Craft::$app->getSites()->getPrimarySite()->uid;
    $manifest = $plugin->getSettings()->getManifest($siteUid);
    $manifest->iconAssetId = $asset->id;

    $config = $manifest->toArray(['name']) + $manifest->assetUids();
    $reloaded = new \justinholtweb\pwa\models\Manifest(['siteUid' => $siteUid, 'iconAssetUid' => $config['iconAssetUid']]);

    return $config['iconAssetUid'] === $asset->uid && $reloaded->iconAssetId === $asset->id ?: json_encode($config);
});

// -------------------------------------------------------------------------------------------
echo "\nPaths built from a site UID\n";

check('a site UID that is a path cannot reach the filesystem', function() use ($plugin) {
    $webrootIndex = rtrim((string)\justinholtweb\pwa\helpers\Files::webroot(), '/') . '/index.php';
    $asset = Asset::find()->kind('image')->filename(['*.png', '*.jpg', '*.jpeg'])->one();

    if ($asset === null) {
        return 'needs a PNG or JPEG asset in the harness';
    }

    $manifest = new \justinholtweb\pwa\models\Manifest(['siteUid' => '../..', 'iconAssetId' => $asset->id]);

    try {
        $plugin->icons->generate($manifest, false);
        $outcome = 'generated';
    } catch (InvalidArgumentException $e) {
        $outcome = 'refused';
    }

    return $outcome === 'refused' && is_file($webrootIndex) ?: "$outcome; index.php " . (is_file($webrootIndex) ? 'present' : 'GONE');
});

check('Files::path() refuses anything outside the PWA directory', function() {
    try {
        \justinholtweb\pwa\helpers\Files::path('icons/../../index.php');

        return 'accepted';
    } catch (InvalidArgumentException $e) {
        return true;
    }
});

check('a manifest for a site that does not exist does not validate', function() {
    $manifest = new \justinholtweb\pwa\models\Manifest(['siteUid' => '../..']);

    return !$manifest->validate(['siteUid']) ?: 'validated';
});

// The CP half needs an admin session. The harness password drifts; Craft's own save path fixes it.
$admin = craft\elements\User::find()->admin()->status(null)->orderBy(['id' => SORT_ASC])->one();
$admin->newPassword = 'claudepassword';
Craft::$app->getElements()->saveElement($admin, false);
Craft::$app->getDb()->createCommand()->update('{{%users}}', ['locked' => false, 'invalidLoginCount' => null], ['id' => $admin->id])->execute();

$cp = new Client(['base_uri' => 'http://127.0.0.1/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);
$csrfFrom = static fn(string $html) => preg_match('/"csrfTokenValue":"([^"]+)"/', $html, $m) ? stripcslashes($m[1]) : null;
$cp->post('admin/actions/users/login', [
    'headers' => ['Accept' => 'application/json'],
    'form_params' => ['loginName' => $admin->username, 'password' => 'claudepassword', 'CRAFT_CSRF_TOKEN' => $csrfFrom((string)$cp->get('admin/login')->getBody())],
]);
$cpToken = $csrfFrom((string)$cp->get('admin/pwa/manifest')->getBody());

check('regenerating icons for a traversal "site UID" is a 404', function() use ($cp, $cpToken) {
    if ($cpToken === null) {
        return 'could not log in to the CP';
    }

    $response = $cp->post('admin/actions/pwa/manifest/regenerate-icons', [
        'headers' => ['Accept' => 'application/json', 'X-CSRF-Token' => $cpToken],
        'form_params' => ['siteUid' => '../..'],
    ]);

    return $response->getStatusCode() === 404 ?: $response->getStatusCode() . ' ' . $response->getBody();
});

check('saving a manifest for a traversal "site UID" is a 404', function() use ($cp, $cpToken) {
    $response = $cp->post('admin/actions/pwa/manifest/save', [
        'headers' => ['Accept' => 'application/json', 'X-CSRF-Token' => $cpToken],
        'form_params' => ['siteUid' => '../../..', 'name' => 'x'],
    ]);

    return $response->getStatusCode() === 404 ?: $response->getStatusCode() . ' ' . substr((string)$response->getBody(), 0, 200);
});

// -------------------------------------------------------------------------------------------
echo "\nSettings mass-assignment\n";

check('a settings save ignores keys that are not on that section', function() use ($cp, $cpToken, $plugin) {
    $value = static fn(string $path) => (new Query())->select('value')->from('{{%projectconfig}}')->where(['path' => "plugins.pwa.settings.$path"])->scalar();
    $before = [$value('extraPushHosts'), $value('routes'), $value('manifests.x.name')];

    $response = $cp->post('admin/actions/pwa/settings/save', [
        'headers' => ['X-CSRF-Token' => $cpToken],
        'form_params' => [
            'section' => 'prompt',
            'settings' => [
                'promptDelay' => '12',
                'extraPushHosts' => ['10.0.0.5', 'internal.example'],
                'routes' => [['pattern' => '*', 'strategy' => 'cacheOnly']],
                'manifests' => ['x' => ['name' => 'x']],
            ],
        ],
    ]);

    $after = [$value('extraPushHosts'), $value('routes'), $value('manifests.x.name')];

    return in_array($response->getStatusCode(), [302, 303], true) && $after === $before && (string)$value('promptDelay') === '12'
        ?: $response->getStatusCode() . ' ' . json_encode([$before, $after, $value('promptDelay')]);
});

check('an address listed in extraPushHosts is still refused', function() use ($plugin) {
    $plugin->getSettings()->extraPushHosts = ['10.0.0.5', '127.0.0.1'];

    try {
        return !Push::isPushEndpoint('https://10.0.0.5/x') && !Push::isPushEndpoint('https://127.0.0.1/x') ?: 'accepted';
    } finally {
        $plugin->getSettings()->extraPushHosts = [];
    }
});

// -------------------------------------------------------------------------------------------
echo "\nSubscription keys and spoofed addresses\n";

check('a subscription with keys of the wrong shape is refused', function() use ($http, $endpointPrefix, $rowFor) {
    $endpoint = $endpointPrefix . 'badkeys';
    $body = json_decode((string)$http->post('index.php?p=actions/pwa/push/subscribe', [
        'headers' => ['Accept' => 'application/json'],
        'form_params' => ['subscription' => ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'abc', 'auth' => 'def']]],
    ])->getBody(), true);

    // 429 is also a refusal, but would not prove anything about the keys.
    return ($body['subscribed'] ?? null) === false && $rowFor($endpoint) === null ?: json_encode($body);
});

check('changing Client-IP / X-Forwarded-For does not buy a new rate-limit budget', function() use ($http, $keys, $endpointPrefix) {
    $statuses = burst(PushController::PER_MINUTE + 1, function(int $i) use ($http, $keys, $endpointPrefix) {
        $fake = "198.51.100.$i";

        return $http->postAsync('index.php?p=actions/pwa/push/subscribe', [
            'headers' => ['Accept' => 'application/json', 'Client-IP' => $fake, 'X-Forwarded-For' => $fake, 'X-Real-IP' => $fake],
            'form_params' => ['subscription' => ['endpoint' => $endpointPrefix . "spoof-$i", 'keys' => $keys]],
        ]);
    });

    return in_array(429, $statuses, true) ?: implode(',', $statuses);
});

check('a signed-in user can run preflight a few times a minute, then is told to wait', function() use ($cp, $cpToken, $admin) {
    Craft::$app->getCache()->delete(sprintf('pwa:rate:preflight:user:%s:%d', sha1((string)$admin->id), intdiv(time(), 60)));
    $results = [];
    for ($i = 0; $i <= justinholtweb\pwa\controllers\PreflightController::RUNS_PER_MINUTE; $i++) {
        $body = json_decode((string)$cp->post('admin/actions/pwa/preflight/run', [
            'headers' => ['Accept' => 'application/json'],
            'form_params' => ['CRAFT_CSRF_TOKEN' => $cpToken],
        ])->getBody(), true);
        $results[] = isset($body['auditId']) ? 'ran' : 'refused';
    }
    Craft::$app->getCache()->delete(sprintf('pwa:rate:preflight:user:%s:%d', sha1((string)$admin->id), intdiv(time(), 60)));

    return array_count_values($results) === ['ran' => justinholtweb\pwa\controllers\PreflightController::RUNS_PER_MINUTE, 'refused' => 1] ?: json_encode($results);
});

check('…and test sends are budgeted per user the same way', function() use ($cp, $cpToken, $admin) {
    $key = sprintf('pwa:rate:push-test:user:%s:%d', sha1((string)$admin->id), intdiv(time(), 60));
    Craft::$app->getCache()->delete($key);
    $codes = [];
    for ($i = 0; $i <= justinholtweb\pwa\controllers\BroadcastController::TEST_PER_MINUTE; $i++) {
        // No such campaign: within budget that's a 404; over it, the budget answers first.
        $codes[] = $cp->post('admin/actions/pwa/broadcast/test', [
            'headers' => ['Accept' => 'application/json'],
            'form_params' => ['CRAFT_CSRF_TOKEN' => $cpToken, 'campaignId' => 0, 'endpoint' => 'https://push.example/x'],
        ])->getStatusCode();
    }
    Craft::$app->getCache()->delete($key);

    return array_count_values($codes) === [404 => justinholtweb\pwa\controllers\BroadcastController::TEST_PER_MINUTE, 400 => 1] ?: json_encode(array_count_values($codes));
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
