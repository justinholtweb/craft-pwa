<?php

namespace justinholtweb\pwa\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;
use Psr\Log\LogLevel;

/**
 * Everything PWA is configured with, in one project-config-backed model.
 *
 * The reason it is one model rather than a set of project config paths with their own handlers is
 * that all of it is *configuration of a single artifact*. A manifest, a flight plan and an install
 * prompt are three views of the same aircraft; splitting them into separately versioned config
 * objects would buy nothing except three more places for a deploy to go half-applied.
 *
 * Nothing here is `required`. A required plugin setting breaks fresh installs outright —
 * `savePluginSettings()` validates the whole model, so one unfilled value blocks saving every
 * other setting and the install is stuck. Validate for correctness when a value is present.
 *
 * The VAPID keypair is the one thing that deliberately does *not* live here: see
 * {@see \justinholtweb\pwa\services\Push}. Project config is committed to source control, and a
 * push private key in git is a push private key on every laptop that ever cloned the repo.
 */
class Settings extends Model
{
    // Where the browser looks for things. Both are served by the plugin's own site routes.
    // ---------------------------------------------------------------------------------------

    /** Master switch. Off means no manifest, no service worker, no injected tags — nothing. */
    public bool $enabled = true;

    /**
     * Path the manifest is served from, relative to the web root.
     *
     * `.webmanifest` is the registered extension; `.json` is more likely to already be handled by
     * a stray rewrite rule, which is why it is not the default.
     */
    public string $manifestPath = 'manifest.webmanifest';

    /**
     * Path the service worker is served from.
     *
     * It must sit at the web root. A worker served from `/assets/sw.js` may only control
     * `/assets/`, whatever the manifest says, unless the response carries `Service-Worker-Allowed`
     * — which is a header a lot of hosting setups quietly strip. Preflight checks the scope that
     * was actually granted rather than the one that was asked for.
     */
    public string $serviceWorkerPath = 'sw.js';

    /** Whether to serve and register the service worker at all. */
    public bool $serviceWorkerEnabled = true;

    /**
     * Whether the plugin writes its own tags into `<head>`.
     *
     * On by default because the alternative — every install editing templates before anything
     * works — is the reason most PWA integrations are abandoned halfway. Turning it off leaves the
     * Twig functions (`pwa.head()`, `pwa.manifestLink()`, `pwa.installPrompt()`) as the only route in.
     */
    public bool $injectHead = true;

    /**
     * URI patterns that are never injected into, even when injection is on.
     *
     * Matched against the request path, `*` allowed. A print stylesheet route, a headless preview
     * or a third-party embed generally does not want a service worker attached to it.
     */
    public array $injectExclude = [];

    // The aircraft itself.
    // ---------------------------------------------------------------------------------------

    /**
     * Per-site manifests, keyed by site UID.
     *
     * Multi-site is the normal case, not the exotic one: two sites on one Craft install are two
     * apps as far as a browser is concerned — different scopes, different start URLs, usually
     * different names — so there is no single manifest to share.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $manifests = [];

    /**
     * Flight plan — the ordered list of routing rules the generated service worker applies.
     *
     * Order matters, first match wins, and the last rule is expected to be a catch-all. Empty
     * means "use the defaults", which is what Lite always does.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $routes = [];

    /**
     * URLs precached when the worker installs — the app shell.
     *
     * Relative to the site's base URL. Anything listed here is fetched on install, so a long list
     * makes the first visit slower for a benefit that only shows up offline.
     *
     * @var array<int, string>
     */
    public array $precache = [];

    /** Whether to precache the offline page and the manifest icons automatically. */
    public bool $precacheEssentials = true;

    /**
     * The offline fallback, per site UID.
     *
     * A URI on the site. The plugin ships a rendered fallback at `pwa/offline` for installs that
     * have not made their own; the point of a real one is that it looks like the site.
     *
     * @var array<string, string>
     */
    public array $offlineUri = [];

    /** Cap on how many responses the runtime page cache holds before the oldest are evicted. */
    public int $maxCachedPages = 60;

    /** Cap on cached static assets — scripts, styles, fonts. */
    public int $maxCachedAssets = 120;

    /** Cap on cached images. */
    public int $maxCachedImages = 80;

    /**
     * Whether to generate iOS splash screens alongside the icons.
     *
     * Twenty-four images, and worth it only because Safari is the one platform that will not
     * derive a launch screen from the background colour and the icon the way every other one does.
     */
    public bool $splashEnabled = true;

    /** Days a runtime-cached response may be served before it is refetched. */
    public int $cacheLifetimeDays = 30;

    // Boarding — the install prompt.
    // ---------------------------------------------------------------------------------------

    /** Whether to show the plugin's own install prompt when the browser offers one. */
    public bool $promptEnabled = true;

    /** Prompt copy. Empty falls back to the manifest name. */
    public string $promptTitle = '';
    public string $promptBody = '';
    public string $promptAccept = 'Install';
    public string $promptDismiss = 'Not now';

    /** Where the prompt sits: `bottom`, `top`, or `inline` (only rendered where you place it). */
    public string $promptPosition = 'bottom';

    /** Seconds on the page before the prompt appears. Zero shows it as soon as it is offered. */
    public int $promptDelay = 10;

    /** Days before a dismissed prompt may be shown again. */
    public int $promptSnoozeDays = 30;

    /**
     * Whether to show iOS visitors the manual "Share → Add to Home Screen" hint.
     *
     * Safari has never fired `beforeinstallprompt`, so on iOS there is nothing to intercept and
     * the only honest option is instructions.
     */
    public bool $promptIosHint = true;

    /**
     * iOS status bar treatment: `default`, `black`, or `black-translucent`.
     *
     * `black-translucent` draws the page underneath the status bar, which looks striking and
     * clips the top of any layout that was not designed for it — so it is not the default.
     */
    public string $iosStatusBarStyle = 'default';

    /** Record installs, launches and offline hits so the flight deck has numbers on it. */
    public bool $trackEvents = true;

    /** Days of event history to keep. */
    public int $eventRetentionDays = 90;

    // Push (Pro).
    // ---------------------------------------------------------------------------------------

    /** Whether visitors may subscribe to push at all. */
    public bool $pushEnabled = false;

    /**
     * `mailto:` or `https:` contact for the VAPID `sub` claim.
     *
     * Push services use it to reach a human when a sender misbehaves; some of them reject
     * subscriptions without one.
     */
    public string $pushSubject = '';

    /** Default icon URL for notifications. Falls back to the manifest's 192px icon. */
    public string $pushIcon = '';

    /** Default badge URL — the monochrome silhouette Android shows in the status bar. */
    public string $pushBadge = '';

    /** Sections (by UID) whose entries offer to broadcast on publish. */
    public array $pushSections = [];

    /** Whether publishing an entry in one of those sections sends automatically. */
    public bool $pushOnPublish = false;

    /** How many subscribers one queue job pushes to before handing off to the next. */
    public int $pushBatchSize = 100;

    /** Consecutive failures before a subscriber is dropped. Gone (410) drops immediately. */
    public int $pushMaxFailures = 3;

    /**
     * The most devices the subscriber table may hold. Zero means no ceiling.
     *
     * Subscribing is anonymous, so the table's size is otherwise decided by whoever sends the
     * most requests. A real audience this large is a site that will raise the number on purpose.
     */
    public int $pushMaxSubscribers = 250000;

    /**
     * New subscriptions accepted per minute across the whole site, from every address together.
     *
     * The per-address limit stops one client; this stops many. Resubscribes from known devices
     * do not count against it.
     */
    public int $pushNewPerMinute = 300;

    /**
     * @var string[] Push-service hosts to accept beyond the browsers' own (Push::PUSH_HOSTS), for a
     * browser whose service isn't listed. `*.example.com` matches subdomains. Config file only:
     * the server POSTs to these hosts, so it is not a CP setting.
     */
    public array $extraPushHosts = [];

    /** Days of delivery records to keep. */
    public int $deliveryRetentionDays = 30;

    // Preflight.
    // ---------------------------------------------------------------------------------------

    /** Whether to run the preflight check on a schedule (Pro). */
    public bool $preflightScheduled = false;

    /** `daily` or `weekly`. */
    public string $preflightCadence = 'weekly';

    /** Hour of day the scheduled check runs, 0–23. */
    public int $preflightHour = 3;

    /** Day of week for a weekly check, 1 (Monday) – 7 (Sunday). */
    public int $preflightWeekday = 1;

    /** Where the scheduled report is emailed. Empty sends to nobody. */
    public array $preflightRecipients = [];

    /** Only email when something failed, rather than every time. */
    public bool $preflightOnlyOnFailure = true;

    /** Audits to keep. */
    public int $auditRetentionDays = 180;

    /** Log verbosity for `storage/logs/pwa.log`. */
    public string $logLevel = LogLevel::INFO;

    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['manifestPath', 'serviceWorkerPath'], 'string'],
            // Not skipped when empty: an empty path is the one value that must be refused, since it
            // would register the manifest route on the site's home page.
            [['manifestPath', 'serviceWorkerPath'], 'validatePath', 'skipOnEmpty' => false],
            [['maxCachedPages', 'maxCachedAssets', 'maxCachedImages'], 'integer', 'min' => 0, 'max' => 5000],
            [['cacheLifetimeDays', 'eventRetentionDays', 'deliveryRetentionDays', 'auditRetentionDays'], 'integer', 'min' => 0, 'max' => 3650],
            [['promptDelay'], 'integer', 'min' => 0, 'max' => 3600],
            [['promptSnoozeDays'], 'integer', 'min' => 0, 'max' => 3650],
            [['pushBatchSize'], 'integer', 'min' => 1, 'max' => 1000],
            [['pushMaxFailures'], 'integer', 'min' => 1, 'max' => 100],
            [['preflightHour'], 'integer', 'min' => 0, 'max' => 23],
            [['preflightWeekday'], 'integer', 'min' => 1, 'max' => 7],
            [['pushMaxSubscribers'], 'integer', 'min' => 0],
            [['pushNewPerMinute'], 'integer', 'min' => 1, 'max' => 100000],
            [['logLevel'], 'in', 'range' => [
                LogLevel::DEBUG, LogLevel::INFO, LogLevel::NOTICE, LogLevel::WARNING,
                LogLevel::ERROR, LogLevel::CRITICAL, LogLevel::ALERT, LogLevel::EMERGENCY,
            ]],
            [['promptPosition'], 'in', 'range' => ['bottom', 'top', 'inline']],
            [['iosStatusBarStyle'], 'in', 'range' => ['default', 'black', 'black-translucent']],
            [['preflightCadence'], 'in', 'range' => ['daily', 'weekly']],
            [['pushSubject'], 'validateSubject'],
            [['preflightRecipients'], 'validateRecipients'],
        ]);
    }

    /**
     * Both served paths have to be plain, relative and inside the web root.
     *
     * A leading slash is forgiven rather than rejected — it is what everybody types — but a scheme,
     * a host or a `..` is not, because each of them means the file will not be where the browser
     * is told to look for it.
     */
    public function validatePath(string $attribute): void
    {
        $value = trim((string)$this->$attribute);

        if ($value === '') {
            $this->addError($attribute, Craft::t('pwa', 'A path is needed.'));
            return;
        }

        if (str_contains($value, '://') || str_starts_with($value, '//')) {
            $this->addError($attribute, Craft::t('pwa', 'Use a path on this site, not a full URL.'));
            return;
        }

        if (str_contains($value, '..') || str_contains($value, '?') || str_contains($value, '#')) {
            $this->addError($attribute, Craft::t('pwa', 'That is not a usable file path.'));
            return;
        }

        $this->$attribute = ltrim($value, '/');
    }

    public function validateSubject(string $attribute): void
    {
        $value = trim((string)App::parseEnv($this->$attribute));

        if ($value === '') {
            return;
        }

        if (!str_starts_with($value, 'mailto:') && !str_starts_with($value, 'https://')) {
            $this->addError($attribute, Craft::t('pwa', 'The VAPID subject must be a mailto: or https:// URL.'));
        }
    }

    public function validateRecipients(string $attribute): void
    {
        foreach ((array)$this->$attribute as $email) {
            $email = trim((string)$email);

            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->addError($attribute, Craft::t('pwa', '“{email}” is not an email address.', ['email' => $email]));
                return;
            }
        }
    }

    /**
     * The manifest for a site, always as a model, never as null.
     *
     * A site that has never been configured still has a manifest — one derived from the site's own
     * name and URL. That is deliberate: installing the plugin and reloading the front end should
     * produce something a browser recognises, and the settings screen should be where you improve
     * it rather than where you start it.
     */
    public function getManifest(?string $siteUid = null): Manifest
    {
        $siteUid ??= Craft::$app->getSites()->getCurrentSite()->uid;
        $config = $this->manifests[$siteUid] ?? [];
        $config['siteUid'] = $siteUid;

        return new Manifest($config);
    }

    /**
     * The flight plan as models, falling back to the defaults when nothing is configured.
     *
     * @return Route[]
     */
    public function getRoutes(): array
    {
        if (empty($this->routes)) {
            return Route::defaults();
        }

        return array_map(static fn(array $config) => new Route($config), array_values($this->routes));
    }

    /** Whether a request path is excluded from head injection. */
    public function isExcluded(string $path): bool
    {
        $path = '/' . ltrim($path, '/');

        foreach ($this->injectExclude as $pattern) {
            $pattern = trim((string)$pattern);

            if ($pattern === '') {
                continue;
            }

            if (fnmatch('/' . ltrim($pattern, '/'), $path, FNM_CASEFOLD)) {
                return true;
            }
        }

        return false;
    }
}
