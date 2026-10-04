<?php

namespace justinholtweb\pwa\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\pwa\Plugin;
use justinholtweb\pwa\push\Encryptor;
use Throwable;
use yii\web\Response;

/**
 * Plugin settings, in four sections.
 *
 * Split rather than presented as one long form because the four have different audiences: general
 * delivery is set once by whoever installs it, offline and prompt are design decisions, and push
 * is an ongoing operational thing with a key in it.
 */
class SettingsController extends Controller
{
    private const SECTIONS = ['general', 'offline', 'prompt', 'push'];

    /**
     * What each section may change.
     *
     * A save only touches the keys its own screen shows. Without the list, any posted
     * `settings[…]` key landed on the model — including the ones that are deliberately not on any
     * screen: `extraPushHosts`, which decides where the server will POST to, and the manifests
     * and flight plan, which have their own screens and their own checks.
     */
    public const EDITABLE = [
        'general' => [
            'enabled', 'manifestPath', 'serviceWorkerEnabled', 'serviceWorkerPath', 'injectHead',
            'injectExclude', 'iosStatusBarStyle', 'splashEnabled', 'trackEvents', 'eventRetentionDays',
        ],
        'offline' => [
            'offlineUri', 'precacheEssentials', 'precache', 'maxCachedPages', 'maxCachedAssets',
            'maxCachedImages', 'cacheLifetimeDays',
        ],
        'prompt' => [
            'promptEnabled', 'promptTitle', 'promptBody', 'promptAccept', 'promptDismiss',
            'promptPosition', 'promptDelay', 'promptSnoozeDays', 'promptIosHint',
        ],
        'push' => [
            'pushEnabled', 'pushSubject', 'pushIcon', 'pushBadge', 'pushOnPublish', 'pushSections',
            'pushBatchSize', 'pushMaxFailures', 'pushMaxSubscribers', 'pushNewPerMinute',
            'deliveryRetentionDays', 'preflightScheduled', 'preflightCadence', 'preflightHour',
            'preflightWeekday', 'preflightRecipients', 'preflightOnlyOnFailure',
        ],
    ];

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // Admins may look on any environment; saving settings is checked separately, against
        // allowAdminChanges. The VAPID actions stay reachable everywhere — they write the
        // database, not project config, and production is where keys get generated.
        $this->requireAdmin(false);

        return true;
    }

    public function actionIndex(string $section = 'general', ?\justinholtweb\pwa\models\Settings $settings = null): Response
    {
        if (!in_array($section, self::SECTIONS, true)) {
            $section = 'general';
        }

        $plugin = Plugin::getInstance();
        $settings ??= $plugin->getSettings();
        $sites = Craft::$app->getSites()->getAllSites();

        $vapid = null;

        if ($section === 'push' && $plugin->isPro()) {
            try {
                $vapid = $plugin->push->getPublicKey();
            } catch (Throwable $e) {
                $vapid = null;
            }
        }

        return $this->renderTemplate('pwa/_settings/index', [
            'readOnly' => !Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
            'section' => $section,
            'settings' => $settings,
            'sites' => $sites,
            'isPro' => $plugin->isPro(),
            'vapidPublicKey' => $vapid,
            'hasEnvKeys' => trim((string)\craft\helpers\App::env('PWA_VAPID_PUBLIC_KEY')) !== '',
            'subscribers' => $plugin->isPro() ? $plugin->push->countSubscribers() : 0,
            'sections' => Craft::$app->getEntries()->getAllSections(),
            'webrootWritable' => \justinholtweb\pwa\helpers\Files::webrootIsWritable(),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $posted = $this->request->getBodyParam('settings', []);
        $section = (string)$this->request->getBodyParam('section', 'general');

        if (!isset(self::EDITABLE[$section])) {
            $section = 'general';
        }

        // Only this section's keys are applied, so saving one section cannot blank out another's
        // values just because they were not on screen — and nothing off-screen can be set at all.
        foreach (self::allowedValues($section, (array)$posted) as $key => $value) {
            $settings->$key = $this->coerce($settings->$key, $value);
        }

        if (!$settings->validate()) {
            $this->setFailFlash(Craft::t('pwa', 'Couldn’t save settings.'));

            Craft::$app->getUrlManager()->setRouteParams(['settings' => $settings, 'section' => $section]);

            return null;
        }

        // No cache bump: anything here that changes the worker changes its config hash, and with
        // it the name of every cache.
        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray())) {
            $this->setFailFlash(Craft::t('pwa', 'Couldn’t save settings.'));

            return null;
        }

        $this->setSuccessFlash(Craft::t('pwa', 'Settings saved.'));

        return $this->redirect('pwa/settings/' . $section);
    }

    /**
     * The posted values a section may set, by key — everything else dropped.
     *
     * @param array<string, mixed> $posted
     * @return array<string, mixed>
     */
    public static function allowedValues(string $section, array $posted): array
    {
        return array_intersect_key($posted, array_flip(self::EDITABLE[$section] ?? []));
    }

    /**
     * Creates the VAPID keypair if there is not one yet.
     *
     * Idempotent, because the interesting failure is somebody pressing it twice and orphaning
     * every subscription on the site. Replacing an existing pair is a different action with a
     * different warning.
     */
    public function actionGenerateKeys(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();

        try {
            $public = $plugin->push->getPublicKey();
        } catch (Throwable $e) {
            return $this->asFailure(Craft::t('pwa', 'Could not generate a keypair: {error}', ['error' => $e->getMessage()]));
        }

        return $this->asSuccess(Craft::t('pwa', 'Keypair ready.'), ['publicKey' => $public]);
    }

    /**
     * Replaces the keypair, which orphans every subscription on the site.
     *
     * Guarded by a typed confirmation in the template rather than a confirm dialog: there is no
     * recovery, and every existing subscriber has to opt in again from a device you do not
     * control.
     */
    public function actionRotateKeys(): Response
    {
        $this->requirePostRequest();

        $confirmation = trim((string)$this->request->getBodyParam('confirm', ''));
        $word = self::rotateWord();

        if (mb_strtolower($confirmation) !== mb_strtolower($word)) {
            return $this->asFailure(Craft::t('pwa', 'Type “{word}” to confirm.', ['word' => $word]));
        }

        $plugin = Plugin::getInstance();
        $dropped = $plugin->push->countSubscribers();

        try {
            $plugin->push->rotateKeys(true);
        } catch (Throwable $e) {
            return $this->asFailure(Craft::t('pwa', 'Could not rotate: {error}', ['error' => $e->getMessage()]));
        }

        return $this->asSuccess(Craft::t('pwa', 'New keypair generated. {count, plural, =0{No subscriptions were} =1{# subscription was} other{# subscriptions were}} dropped and will need to opt in again.', [
            'count' => $dropped,
        ]));
    }

    /**
     * The word typed to confirm a rotation — translated, and the same string the template asks
     * for, so a translated screen does not ask for one word and accept another.
     */
    public static function rotateWord(): string
    {
        return Craft::t('pwa', 'rotate');
    }

    /** Checks that an imported keypair is actually a pair, before anything depends on it. */
    public function actionVerifyKeys(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        try {
            $keys = Plugin::getInstance()->push->getKeys();
            $usable = Encryptor::isUsableKey($keys['privateKey']);
        } catch (Throwable $e) {
            return $this->asFailure(Craft::t('pwa', 'The keypair could not be read: {error}', ['error' => $e->getMessage()]));
        }

        return $usable
            ? $this->asSuccess(Craft::t('pwa', 'The keypair is usable.'))
            : $this->asFailure(Craft::t('pwa', 'The private key could not be read.'));
    }

    /**
     * Keeps a posted value the same type as the setting it lands on.
     *
     * Everything from a form is a string, and a `false` that arrives as `"0"` and is stored as
     * `"0"` will be truthy the moment somebody reads it from project config in PHP.
     */
    private function coerce(mixed $current, mixed $value): mixed
    {
        if (is_bool($current)) {
            return (bool)$value;
        }

        if (is_int($current)) {
            return (int)$value;
        }

        if (is_float($current)) {
            return (float)$value;
        }

        if (is_array($current)) {
            if (is_array($value)) {
                $cleaned = array_filter(
                    array_map(static fn($item) => is_string($item) ? trim($item) : $item, $value),
                    static fn($item) => $item !== '' && $item !== null,
                );

                // Keys are kept for the settings that are maps — the per-site offline pages are
                // keyed by site UID, and renumbering them would detach every one of them from its
                // site. Lists are renumbered, so a removed row does not leave a gap.
                return array_is_list($value) ? array_values($cleaned) : $cleaned;
            }

            // Multi-line textareas are the natural way to type a list of URI patterns.
            return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string)$value) ?: [])));
        }

        return is_string($value) ? trim($value) : $value;
    }
}
