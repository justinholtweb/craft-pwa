<?php

namespace justinholtweb\pwa\web;

use Craft;
use craft\helpers\Html;
use craft\helpers\Template;
use craft\models\Site;
use justinholtweb\pwa\models\Manifest;
use justinholtweb\pwa\Plugin;
use Twig\Markup;

/**
 * `{{ pwa }}` in templates.
 *
 * The whole surface is read-only and side-effect free, with one exception noted below. A template
 * function that quietly changed configuration would be a template function that behaves
 * differently depending on whether a page was cached.
 */
class Variable
{
    /**
     * Every tag the plugin wants in `<head>`.
     *
     * Placing this yourself and turning off automatic injection is the tidier arrangement, and the
     * only one that gives you control over where in the head it lands.
     */
    public function head(?int $siteId = null): Markup
    {
        return Head::render($this->site($siteId));
    }

    /** Just the manifest link, for a template that wants to place the rest itself. */
    public function manifestLink(?int $siteId = null): Markup
    {
        return Template::raw(Html::tag('link', '', [
            'rel' => 'manifest',
            'href' => $this->manifestUrl($siteId),
            'crossorigin' => 'use-credentials',
        ]));
    }

    public function manifestUrl(?int $siteId = null): string
    {
        return Plugin::getInstance()->serviceWorker->manifestUrl($this->site($siteId));
    }

    public function serviceWorkerUrl(?int $siteId = null): string
    {
        return Plugin::getInstance()->serviceWorker->scriptUrl($this->site($siteId));
    }

    /** The manifest as data, for a template that wants to render something from it. */
    public function manifest(?int $siteId = null): Manifest
    {
        return Plugin::getInstance()->manifests->forSite($this->site($siteId));
    }

    /** @return array<string, mixed> */
    public function manifestData(?int $siteId = null): array
    {
        return Plugin::getInstance()->manifests->build($this->site($siteId));
    }

    public function iconUrl(int $size = 192, ?int $siteId = null): ?string
    {
        return Plugin::getInstance()->icons->iconUrl($this->site($siteId)->uid, "icon-{$size}.png");
    }

    /**
     * An install button that only renders where an install is possible.
     *
     * The `data-pwa-prompt` element is where the runtime puts its prompt when one is offered. It
     * stays empty otherwise — on a device that has already installed the app, or a browser that
     * does not support installing, there is nothing to show and a button that does nothing is
     * worse than no button.
     */
    public function installPrompt(array $options = []): Markup
    {
        return Template::raw(Html::tag('div', '', [
            'data-pwa-prompt' => true,
            'class' => $options['class'] ?? null,
            'id' => $options['id'] ?? null,
        ]));
    }

    public function isEnabled(): bool
    {
        return Plugin::getInstance()->getSettings()->enabled;
    }

    /** Whether push is available to visitors of this site right now. */
    public function pushEnabled(): bool
    {
        $plugin = Plugin::getInstance();

        return $plugin->isPro() && $plugin->getSettings()->pushEnabled;
    }

    public function vapidPublicKey(): ?string
    {
        return $this->pushEnabled() ? Plugin::getInstance()->push->getPublicKey() : null;
    }

    /** How many devices are subscribed — for a "join 1,240 readers" line, and nothing more. */
    public function subscriberCount(?int $siteId = null): int
    {
        return $this->pushEnabled() ? Plugin::getInstance()->push->countSubscribers($this->site($siteId)->id) : 0;
    }

    private function site(?int $siteId): Site
    {
        if ($siteId !== null) {
            $site = Craft::$app->getSites()->getSiteById($siteId);

            if ($site !== null) {
                return $site;
            }
        }

        return Craft::$app->getSites()->getCurrentSite();
    }
}
