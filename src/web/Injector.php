<?php

namespace justinholtweb\pwa\web;

use Craft;
use craft\web\Response;
use craft\web\TemplateResponseFormatter;
use justinholtweb\pwa\Plugin;
use yii\base\Event;

/**
 * Writes the plugin's tags into the response's `<head>`.
 *
 * Injecting into finished HTML is not the elegant option, and it is the right one. The elegant
 * option — registering tags with Craft's view and letting `{{ head() }}` print them — only works
 * on templates that call `head()`, and a large share of real Craft sites do not, because nothing
 * ever needed it. The choice is between a plugin that works on every site and a plugin that works
 * on the tidy ones.
 *
 * It is nonetheless narrow about when it acts: site requests only, HTML only, successful responses
 * only, and never when the path has been excluded. Anything it is unsure about, it leaves alone.
 */
class Injector
{
    public static function register(): void
    {
        Event::on(Response::class, Response::EVENT_AFTER_PREPARE, static function(Event $event) {
            /** @var Response $response */
            $response = $event->sender;

            self::inject($response);
        });
    }

    private static function inject(Response $response): void
    {
        $plugin = Plugin::getInstance();

        if ($plugin === null) {
            return;
        }

        $settings = $plugin->getSettings();

        if (!$settings->enabled || !$settings->injectHead) {
            return;
        }

        if ($response->getIsRedirection() || !$response->getIsSuccessful()) {
            return;
        }

        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest() || !$request->getIsSiteRequest() || $request->getIsAjax()) {
            return;
        }

        // Live preview renders inside the control panel's iframe. Registering a service worker
        // there attaches one to a URL the author is only borrowing.
        if ($request->getIsPreview()) {
            return;
        }

        if ($settings->isExcluded($request->getPathInfo())) {
            return;
        }

        // Craft renders site templates through its own `template` format, not Yii's `html` — so
        // an allowlist of Yii's formats matches nothing on a normal Craft page, which is a fine
        // way to build a feature that silently never runs. The content type is the honest signal,
        // and by this point in the response lifecycle it is set.
        $contentType = strtolower((string)($response->getHeaders()->get('content-type') ?? ''));

        if ($contentType !== '' && !str_contains($contentType, 'html')) {
            return;
        }

        if (!in_array($response->format, [
            Response::FORMAT_HTML,
            Response::FORMAT_RAW,
            TemplateResponseFormatter::FORMAT,
        ], true)) {
            return;
        }

        $content = $response->content;

        if (!is_string($content) || $content === '') {
            return;
        }

        // A page that already links a manifest is a page somebody has already thought about —
        // whether with `{{ pwa.head() }}` or with their own tag. Injecting a second one would
        // produce exactly the duplicate the preflight check warns about.
        if (preg_match('/<link[^>]+rel=["\']?manifest/i', $content) === 1) {
            return;
        }

        $position = stripos($content, '</head>');

        if ($position === false) {
            return;
        }

        $html = Head::html();

        if ($html === '') {
            return;
        }

        $response->content = substr($content, 0, $position) . $html . substr($content, $position);
    }
}
