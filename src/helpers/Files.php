<?php

namespace justinholtweb\pwa\helpers;

use Craft;
use craft\helpers\FileHelper;
use craft\helpers\UrlHelper;

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

    /** Whether generated files can be written to the web root. */
    public static function webrootIsWritable(): bool
    {
        $webroot = Craft::getAlias('@webroot', false);

        if (!is_string($webroot) || $webroot === '' || !is_dir($webroot)) {
            return false;
        }

        $target = $webroot . DIRECTORY_SEPARATOR . self::DIR;

        if (is_dir($target)) {
            return is_writable($target);
        }

        return is_writable($webroot);
    }

    /** Absolute directory generated files are written to, created if need be. */
    public static function basePath(): string
    {
        $base = self::webrootIsWritable()
            ? Craft::getAlias('@webroot') . DIRECTORY_SEPARATOR . self::DIR
            : Craft::$app->getPath()->getStoragePath() . DIRECTORY_SEPARATOR . self::DIR;

        FileHelper::createDirectory($base);

        return $base;
    }

    /** Absolute path for a file inside that directory. */
    public static function path(string $relative): string
    {
        return self::basePath() . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $relative), DIRECTORY_SEPARATOR);
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

    /** Deletes a whole subdirectory of generated files — used when regenerating an icon set. */
    public static function clear(string $relative): void
    {
        $path = self::path($relative);

        if (is_dir($path)) {
            FileHelper::clearDirectory($path);
        }
    }
}
