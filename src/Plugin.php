<?php

namespace justinholtweb\pwa;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\elements\Entry;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\UrlHelper;
use craft\log\MonologTarget;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\Application;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use justinholtweb\pwa\helpers\Cadence;
use justinholtweb\pwa\helpers\Files;
use justinholtweb\pwa\models\Settings;
use justinholtweb\pwa\queue\PreflightJob;
use justinholtweb\pwa\services\Campaigns;
use justinholtweb\pwa\services\Events;
use justinholtweb\pwa\services\Icons;
use justinholtweb\pwa\services\Manifests;
use justinholtweb\pwa\services\Notifications;
use justinholtweb\pwa\services\Preflight;
use justinholtweb\pwa\services\Push;
use justinholtweb\pwa\services\ServiceWorker;
use justinholtweb\pwa\web\Injector;
use justinholtweb\pwa\web\Variable;
use Psr\Log\LogLevel;
use yii\base\Event;

/**
 * PWA — turns a Craft site into an installable app.
 *
 * There are three artifacts a progressive web app actually consists of, and a browser will refuse
 * to install one if any of them is wrong: a manifest describing the app, a service worker deciding
 * what happens when the network does not answer, and a set of icons at the sizes each platform
 * insists on. Everything in this plugin exists to produce those three correctly from what Craft
 * already knows, and — because "correctly" is not something you can tell by looking — to check the
 * result over HTTP afterwards and say plainly what is wrong with it.
 *
 * Lite is a complete PWA: manifest, icons, worker, offline page, install prompt, preflight check.
 * Pro adds the things a site grows into — a hand-written flight plan, web push, and a scheduled
 * check that emails somebody when a deploy quietly breaks the manifest.
 *
 * @property-read Manifests $manifests
 * @property-read Icons $icons
 * @property-read ServiceWorker $serviceWorker
 * @property-read Preflight $preflight
 * @property-read Push $push
 * @property-read Campaigns $campaigns
 * @property-read Events $events
 * @property-read Notifications $notifications
 * @property-read Settings $settings
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    /** See the flight deck, the preflight report and the subscriber list. */
    public const PERMISSION_VIEW = 'pwa:view';

    /** Change the manifest, the flight plan and the prompt. */
    public const PERMISSION_MANAGE = 'pwa:manage';

    /** Send a push notification — which reaches people who are not on the site. Its own permission. */
    public const PERMISSION_BROADCAST = 'pwa:broadcast';

    public const LOG_CATEGORY = 'pwa';

    /** Cache key throttling the scheduled-preflight check so it costs one lookup per hour. */
    private const SCHEDULE_KEY = 'pwa:schedule-checked';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    public static function editions(): array
    {
        return [self::EDITION_LITE, self::EDITION_PRO];
    }

    public static function config(): array
    {
        return [
            'components' => [
                'manifests' => Manifests::class,
                'icons' => Icons::class,
                'serviceWorker' => ServiceWorker::class,
                'preflight' => Preflight::class,
                'push' => Push::class,
                'campaigns' => Campaigns::class,
                'events' => Events::class,
                'notifications' => Notifications::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerLogging();
        $this->registerPermissions();
        $this->registerCpUrlRules();
        $this->registerSiteUrlRules();
        $this->registerVariable();
        $this->registerGarbageCollection();

        // Everything below only matters on a site request, and half of it costs a settings read,
        // so none of it is set up for a console command or a control panel page.
        if (Craft::$app->getRequest()->getIsSiteRequest() && !Craft::$app->getRequest()->getIsConsoleRequest()) {
            Injector::register();
        }

        if ($this->isPro()) {
            $this->registerPublishHook();
            $this->registerScheduleTrigger();
        }
    }

    /**
     * Whether Pro features are available.
     *
     * Every edition check goes through here rather than calling `is()` directly, so the boundary
     * is auditable in one place.
     */
    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO, '>=');
    }

    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('pwa/settings'));
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $user = Craft::$app->getUser();

        $subnav = [
            'deck' => ['label' => Craft::t('pwa', 'Flight deck'), 'url' => 'pwa'],
            'preflight' => ['label' => Craft::t('pwa', 'Preflight'), 'url' => 'pwa/preflight'],
        ];

        if ($this->isPro() && $user->checkPermission(self::PERMISSION_BROADCAST)) {
            $subnav['broadcast'] = ['label' => Craft::t('pwa', 'Broadcast'), 'url' => 'pwa/broadcast'];
        }

        if ($user->checkPermission(self::PERMISSION_MANAGE)) {
            $subnav['manifest'] = ['label' => Craft::t('pwa', 'Manifest'), 'url' => 'pwa/manifest'];
            $subnav['flightplan'] = ['label' => Craft::t('pwa', 'Flight plan'), 'url' => 'pwa/flight-plan'];
        }

        if ($user->getIsAdmin()) {
            $subnav['settings'] = ['label' => Craft::t('pwa', 'Settings'), 'url' => 'pwa/settings'];
        }

        $item['subnav'] = $subnav;

        return $item;
    }

    // ------------------------------------------------------------------------- logging

    public static function info(string $message): void
    {
        Craft::info($message, self::LOG_CATEGORY);
    }

    public static function warning(string $message): void
    {
        Craft::warning($message, self::LOG_CATEGORY);
    }

    public static function error(string $message): void
    {
        Craft::error($message, self::LOG_CATEGORY);
    }

    private function registerLogging(): void
    {
        Craft::getLogger()->dispatcher->targets[] = new MonologTarget([
            'name' => self::LOG_CATEGORY,
            'categories' => [self::LOG_CATEGORY],
            'level' => $this->getSettings()->logLevel ?: LogLevel::INFO,
            'logContext' => false,
            'allowLineBreaks' => true,
            'maxFiles' => 10,
        ]);
    }

    // ------------------------------------------------------------------------ url rules

    private function registerCpUrlRules(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules['pwa'] = 'pwa/deck/index';
            $event->rules['pwa/preflight'] = 'pwa/preflight/index';
            $event->rules['pwa/preflight/<auditId:\d+>'] = 'pwa/preflight/detail';
            $event->rules['pwa/manifest'] = 'pwa/manifest/index';
            $event->rules['pwa/flight-plan'] = 'pwa/flight-plan/index';
            $event->rules['pwa/broadcast'] = 'pwa/broadcast/index';
            $event->rules['pwa/broadcast/new'] = 'pwa/broadcast/edit';
            $event->rules['pwa/broadcast/<campaignId:\d+>'] = 'pwa/broadcast/edit';
            $event->rules['pwa/broadcast/subscribers'] = 'pwa/broadcast/subscribers';
            $event->rules['pwa/settings'] = 'pwa/settings/index';
            $event->rules['pwa/settings/<section:[\w\-]+>'] = 'pwa/settings/index';
        });
    }

    /**
     * The three URLs a browser fetches, plus the fallback file route.
     *
     * These are registered as *site* URL rules rather than written as files because both of the
     * important ones have to be at the web root — a manifest can live anywhere, but a service
     * worker's scope is decided by the directory it is served from — and writing to the web root on
     * every settings save is how a plugin breaks the first time it meets a read-only deploy.
     */
    private function registerSiteUrlRules(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_SITE_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $settings = $this->getSettings();

            if (!$settings->enabled) {
                return;
            }

            $event->rules[ltrim($settings->manifestPath, '/')] = 'pwa/manifest/serve';

            if ($settings->serviceWorkerEnabled) {
                $event->rules[ltrim($settings->serviceWorkerPath, '/')] = 'pwa/worker/serve';
            }

            $event->rules[Files::DIR . '/offline'] = 'pwa/worker/offline';

            // Only used when Craft's asset publishing is unavailable, which is rare and worth
            // surviving rather than debugging.
            $event->rules[Files::DIR . '/runtime.js'] = 'pwa/worker/runtime';

            // Only needed where the web root could not be written to, but registered either way:
            // an environment that changes from writable to not must not 404 its own icons.
            $event->rules[Files::DIR . '/file/<path:.+>'] = 'pwa/worker/file';
        });
    }

    private function registerVariable(): void
    {
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, static function(Event $event) {
            /** @var CraftVariable $variable */
            $variable = $event->sender;
            $variable->set('pwa', Variable::class);
        });
    }

    // ---------------------------------------------------------------------- permissions

    private function registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function(RegisterUserPermissionsEvent $event) {
            $permissions = [
                self::PERMISSION_VIEW => [
                    'label' => Craft::t('pwa', 'View the flight deck and preflight reports'),
                ],
                self::PERMISSION_MANAGE => [
                    'label' => Craft::t('pwa', 'Manage the manifest and flight plan'),
                ],
            ];

            if ($this->isPro()) {
                $permissions[self::PERMISSION_BROADCAST] = [
                    'label' => Craft::t('pwa', 'Send push notifications'),
                    'info' => Craft::t('pwa', 'A broadcast reaches people who are not on the site, on devices they are holding. It cannot be recalled.'),
                ];
            }

            $event->permissions[] = [
                'heading' => Craft::t('pwa', 'PWA'),
                'permissions' => $permissions,
            ];
        });
    }

    // -------------------------------------------------------------------------- upkeep

    private function registerGarbageCollection(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, function() {
            $this->events->prune();
            $this->campaigns->pruneDeliveries();
            $this->preflight->prune();
        });
    }

    /**
     * Offers a broadcast when an entry in a watched section is published.
     *
     * Deliberately not automatic unless somebody has said so. A plugin that pushes a notification
     * to every subscriber the moment an author fixes a typo is a plugin that gets uninstalled the
     * same afternoon — Craft fires a save for drafts, revisions, propagations and resaves, and
     * only one of those is news.
     */
    private function registerPublishHook(): void
    {
        Event::on(Entry::class, Entry::EVENT_AFTER_PROPAGATE, function(\craft\events\ModelEvent $event) {
            $settings = $this->getSettings();

            if (!$settings->pushEnabled || !$settings->pushOnPublish || empty($settings->pushSections)) {
                return;
            }

            /** @var Entry $entry */
            $entry = $event->sender;

            if ($entry->getIsDraft() || $entry->getIsRevision() || $entry->propagating || $entry->resaving) {
                return;
            }

            // Only the first time it goes live. `firstSave` is not enough — an entry can be saved
            // disabled and enabled later, and that is the moment that counts as publishing.
            if (!$entry->enabled || $entry->getStatus() !== Entry::STATUS_LIVE) {
                return;
            }

            $section = $entry->getSection();

            if ($section === null || !in_array($section->uid, $settings->pushSections, true)) {
                return;
            }

            if ($this->campaigns->getCampaignByEntryId((int)$entry->id) !== null) {
                return;
            }

            $campaign = $this->campaigns->fromEntry($entry);
            $campaign->body = Craft::t('pwa', 'New on {site}', ['site' => $entry->getSite()->getName()]);

            if ($this->campaigns->save($campaign)) {
                $this->campaigns->broadcast($campaign);
            }
        });
    }

    /**
     * Queues the scheduled preflight when one is due.
     *
     * Hung off control panel requests rather than requiring a cron entry, because the sites that
     * most need to be told their manifest broke are the ones nobody set a cron up for. The cache
     * key means the check itself costs one lookup an hour, and the queue's own uniqueness means
     * two simultaneous page loads cannot both queue it.
     */
    private function registerScheduleTrigger(): void
    {
        if (!Craft::$app->getRequest()->getIsCpRequest() || Craft::$app->getRequest()->getIsConsoleRequest()) {
            return;
        }

        Event::on(Application::class, Application::EVENT_AFTER_REQUEST, function() {
            $settings = $this->getSettings();

            if (!$settings->preflightScheduled) {
                return;
            }

            $cache = Craft::$app->getCache();

            if ($cache->get(self::SCHEDULE_KEY) !== false) {
                return;
            }

            $cache->set(self::SCHEDULE_KEY, true, 3600);

            $latest = $this->preflight->getLatest();
            $lastScheduled = null;

            foreach ($this->preflight->getAudits(null, 20) as $audit) {
                if ($audit->trigger === 'scheduled') {
                    $lastScheduled = $audit->dateCreated;
                    break;
                }
            }

            if (!Cadence::isDue($settings->preflightCadence, $settings->preflightHour, $settings->preflightWeekday, $lastScheduled)) {
                return;
            }

            Craft::$app->getQueue()->push(new PreflightJob());

            self::info('Scheduled preflight queued' . ($latest ? '.' : ' (first run).'));
        });
    }
}
