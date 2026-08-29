<?php

namespace justinholtweb\pwa\web;

use Craft;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\Template;
use craft\models\Site;
use justinholtweb\pwa\Plugin;
use Throwable;
use Twig\Markup;

/**
 * The tags that go in `<head>`, built once and used by both routes into the page.
 *
 * There are two ways this markup reaches a template — injected into the response, or written by
 * hand as `{{ pwa.head() }}` — and they have to produce exactly the same thing. Anything else
 * means a site that switches from one to the other quietly changes behaviour, and the preflight
 * check starts disagreeing with the settings screen.
 *
 * What is *not* here is as considered as what is. There is no viewport tag: it changes how every
 * page of the site is laid out and that is not a plugin's decision. There is no favicon override
 * beyond the generated PNGs, because a site that already has one has usually thought about it.
 */
class Head
{
    /** The whole block, ready to be printed. */
    public static function render(?Site $site = null): Markup
    {
        return Template::raw(self::html($site));
    }

    public static function html(?Site $site = null): string
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (!$settings->enabled) {
            return '';
        }

        $site ??= Craft::$app->getSites()->getCurrentSite();
        $manifest = $settings->getManifest($site->uid);
        $tags = [];

        $tags[] = Html::tag('link', '', [
            'rel' => 'manifest',
            'href' => $plugin->serviceWorker->manifestUrl($site),

            // Without this a manifest is fetched anonymously, so a site behind any kind of session
            // wall serves its login page as the manifest and the install silently never happens.
            'crossorigin' => 'use-credentials',
        ]);

        if (trim($manifest->themeColor) !== '') {
            $tags[] = Html::tag('meta', '', ['name' => 'theme-color', 'content' => $manifest->themeColor]);
        }

        $tags[] = Html::tag('meta', '', ['name' => 'mobile-web-app-capable', 'content' => 'yes']);

        // The `apple-` prefixed pair is what iOS still reads. Both are needed: the unprefixed one
        // is the standard, the prefixed one is what Safari implements.
        $tags[] = Html::tag('meta', '', ['name' => 'apple-mobile-web-app-capable', 'content' => 'yes']);
        $tags[] = Html::tag('meta', '', [
            'name' => 'apple-mobile-web-app-status-bar-style',
            'content' => $settings->iosStatusBarStyle,
        ]);
        $tags[] = Html::tag('meta', '', [
            'name' => 'apple-mobile-web-app-title',
            'content' => $manifest->getEffectiveShortName(),
        ]);

        $appleIcon = $plugin->icons->iconUrl($site->uid, 'apple-touch-icon.png');

        if ($appleIcon !== null) {
            $tags[] = Html::tag('link', '', ['rel' => 'apple-touch-icon', 'href' => $appleIcon]);
        }

        foreach ([32, 16] as $size) {
            $favicon = $plugin->icons->iconUrl($site->uid, "favicon-{$size}.png");

            if ($favicon !== null) {
                $tags[] = Html::tag('link', '', [
                    'rel' => 'icon',
                    'type' => 'image/png',
                    'sizes' => "{$size}x{$size}",
                    'href' => $favicon,
                ]);
            }
        }

        if ($settings->splashEnabled) {
            foreach ($plugin->icons->splashLinks($site->uid) as $splash) {
                $tags[] = Html::tag('link', '', [
                    'rel' => 'apple-touch-startup-image',
                    'href' => $splash['url'],
                    'media' => $splash['media'],
                ]);
            }
        }

        $tags[] = self::configTag($site);
        $tags[] = Html::tag('script', '', ['src' => self::runtimeUrl(), 'defer' => true]);

        return implode("\n", array_filter($tags)) . "\n";
    }

    /**
     * The runtime's configuration, as JSON in a script tag rather than as inline code.
     *
     * A site with a strict Content-Security-Policy can serve `application/json` in a script tag
     * without an unsafe-inline exception, and the runtime reads it from there. Writing the same
     * values as JavaScript statements would work everywhere except the sites that care most.
     */
    private static function configTag(Site $site): string
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $manifest = $settings->getManifest($site->uid);

        $config = [
            'swUrl' => $settings->serviceWorkerEnabled ? $plugin->serviceWorker->scriptUrl($site) : null,
            'scope' => $manifest->getEffectiveScope(),
            'appName' => $manifest->getEffectiveName(),
            'themeColor' => $manifest->themeColor,
            'icon' => $plugin->icons->iconUrl($site->uid, 'icon-192.png'),
            'promptEnabled' => $settings->promptEnabled,
            'promptTitle' => $settings->promptTitle !== ''
                ? $settings->promptTitle
                : Craft::t('pwa', 'Install {name}', ['name' => $manifest->getEffectiveName()]),
            'promptBody' => $settings->promptBody,
            'promptAccept' => $settings->promptAccept,
            'promptDismiss' => $settings->promptDismiss,
            'promptDismissLabel' => Craft::t('pwa', 'Got it'),
            'promptPosition' => $settings->promptPosition,
            'promptDelay' => $settings->promptDelay,
            'snoozeDays' => $settings->promptSnoozeDays,
            'iosHint' => $settings->promptIosHint,
            'iosBody' => Craft::t('pwa', 'Tap the Share button, then “Add to Home Screen”.'),
            'trackEvents' => $settings->trackEvents,
            'eventUrl' => $settings->trackEvents ? \craft\helpers\UrlHelper::actionUrl('pwa/events/record') : null,
        ];

        if ($settings->pushEnabled && $plugin->isPro()) {
            try {
                $config['pushEnabled'] = true;
                $config['vapidPublicKey'] = $plugin->push->getPublicKey();
                $config['subscribeUrl'] = \craft\helpers\UrlHelper::actionUrl('pwa/push/subscribe');
                $config['unsubscribeUrl'] = \craft\helpers\UrlHelper::actionUrl('pwa/push/unsubscribe');
            } catch (Throwable $e) {
                // A missing keypair must not take the page down with it; preflight reports it.
                Plugin::error('Push configuration could not be built: ' . $e->getMessage());
                $config['pushEnabled'] = false;
            }
        }

        // No CSRF token, deliberately. This block is injected into *every* HTML response, and a
        // per-session token in every response is a per-session token in every full-page cache —
        // which is how a static cache starts serving one visitor's token to everybody. The
        // endpoints it would have protected (subscribe, unsubscribe, the event beacon) do not
        // validate CSRF anyway, because the service worker that calls them has no page and no
        // token; see PushController for what that does and does not cost.

        return Html::tag('script', Json::encode($config), ['type' => 'application/json', 'id' => 'pwa-config']);
    }

    /**
     * Where the page-side runtime is served from.
     *
     * Published through Craft's own asset manager, which puts it under `cpresources` with a hash
     * in the path — so it can be cached forever and still change the moment the plugin is updated.
     */
    public static function runtimeUrl(): string
    {
        try {
            // A path rather than an alias, so this works whether or not the plugin installer
            // registered one for this namespace.
            return Craft::$app->getAssetManager()->getPublishedUrl(
                dirname(__DIR__) . '/resources/runtime',
                true,
                'pwa.js',
            );
        } catch (Throwable $e) {
            Plugin::error('Could not publish the runtime script: ' . $e->getMessage());

            // The fallback route reads the same file straight off disk. Slower, and always there.
            return \craft\helpers\UrlHelper::siteUrl(\justinholtweb\pwa\helpers\Files::DIR . '/runtime.js');
        }
    }
}
