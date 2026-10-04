<?php

namespace justinholtweb\pwa\helpers;

use Craft;
use craft\helpers\App;
use craft\helpers\FileHelper;
use craft\helpers\UrlHelper;
use InvalidArgumentException;

/**
 * Where the plugin's generated files live, and how a browser reaches them.
 *
 * Two answers, in order of preference. If the web root is writable — which it is on most Craft
 * installs, and always in development — icons and splash screens are written there and served by
 * the web server as ordinary static files. If it is not (read-only deploys, containerised
 * production, `public/` owned by a deploy user the PHP process is not), they go to `storage/` and
 * are served by a controller action instead.
 *
 * The fallback matters more than it looks. A PWA whose icons 404 does not fail loudly: it installs,
 * and shows a grey square on the home screen forever.
 */
class Files
{
    public const DIR = 'pwa';

    /** Memoized for the request: it is asked several times per page and touches the disk. */
    private static ?bool $writable = null;

    /**
     * The web root, or null if this process cannot tell where it is.
     *
     * A web request always knows. A console command or a queue job only knows if somebody told
     * it — the `@webroot` alias, or `CRAFT_WEB_ROOT` — and guessing differently from the web
     * process means icons are written to one place and served from another.
     */
    public static function webroot(): ?string
    {
        $candidates = [
            Craft::getAlias('@webroot', false),
            App::env('CRAFT_WEB_ROOT'),
            defined('CRAFT_WEB_ROOT') ? constant('CRAFT_WEB_ROOT') : null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_dir($candidate)) {
                return rtrim($candidate, '/\\');
            }
        }

        return null;
    }

    /** Whether generated files can be written to the web root. */
    public static function webrootIsWritable(): bool
    {
        if (self::$writable !== null) {
            return self::$writable;
        }

        $webroot = self::webroot();

        if ($webroot === null) {
            return self::$writable = false;
        }

        $target = $webroot . DIRECTORY_SEPARATOR . self::DIR;

        return self::$writable = is_dir($target) ? is_writable($target) : is_writable($webroot);
    }

    /** Forgets the memoized answer — for tests, and after creating the directory. */
    public static function reset(): void
    {
        self::$writable = null;
    }

    /** Absolute directory generated files are written to, created if need be. */
    public static function basePath(): string
    {
        $base = self::webrootIsWritable()
            ? self::webroot() . DIRECTORY_SEPARATOR . self::DIR
            : Craft::$app->getPath()->getStoragePath() . DIRECTORY_SEPARATOR . self::DIR;

        FileHelper::createDirectory($base);

        return $base;
    }

    /**
     * Absolute path for a file inside that directory.
     *
     * Refuses anything that resolves outside it. Every caller builds the relative part from
     * values it controls, and this is what makes sure that stays true: a `..` that got this far
     * once was a request away from `clearDirectory()` on the web root.
     *
     * @throws InvalidArgumentException
     */
    public static function path(string $relative): string
    {
        $path = self::within(self::basePath(), $relative);

        if ($path === null) {
            throw new InvalidArgumentException('Refusing a path outside the PWA directory: ' . $relative);
        }

        return $path;
    }

    /**
     * `$relative` resolved under `$base`, or null if it escapes it. Pure, so it can be tested.
     */
    public static function within(string $base, string $relative): ?string
    {
        $base = FileHelper::normalizePath($base);
        $relative = ltrim(str_replace('\\', '/', $relative), '/');

        if ($relative === '') {
            return $base;
        }

        $target = FileHelper::normalizePath($base . DIRECTORY_SEPARATOR . $relative);

        return str_starts_with($target, $base . DIRECTORY_SEPARATOR) ? $target : null;
    }

    /**
     * The URL a browser fetches a generated file from.
     *
     * Always site-relative rather than absolute: the manifest, the icons and the page that
     * references them have to agree on origin, and an absolute URL built from a base URL that
     * differs by so much as a `www.` puts the icons on a cross-origin request the manifest is not
     * allowed to make.
     */
    public static function url(string $relative, ?int $siteId = null): string
    {
        $relative = ltrim($relative, '/');

        if (self::webrootIsWritable()) {
            return '/' . self::DIR . '/' . $relative;
        }

        return UrlHelper::siteUrl(self::DIR . '/file/' . $relative, null, null, $siteId);
    }

    public static function exists(string $relative): bool
    {
        return is_file(self::path($relative));
    }

    /**
     * Deletes a whole subdirectory of generated files — used when regenerating an icon set.
     *
     * Never the base directory itself, and never anything outside it.
     */
    public static function clear(string $relative): void
    {
        $path = self::path($relative);

        if ($path === FileHelper::normalizePath(self::basePath())) {
            throw new InvalidArgumentException('Refusing to clear the whole PWA directory.');
        }

        if (is_dir($path)) {
            FileHelper::clearDirectory($path);
        }
    }
}
