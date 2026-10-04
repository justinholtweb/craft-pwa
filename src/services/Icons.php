<?php

namespace justinholtweb\pwa\services;

use Craft;
use craft\base\Component;
use craft\elements\Asset;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use Imagine\Gd\Imagine as GdImagine;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Imagine\Image\ImagineInterface;
use Imagine\Image\Palette\RGB;
use Imagine\Image\Point;
use Imagine\Imagick\Imagine as ImagickImagine;
use InvalidArgumentException;
use justinholtweb\pwa\helpers\Files;
use justinholtweb\pwa\helpers\Sites;
use justinholtweb\pwa\models\Manifest;
use justinholtweb\pwa\Plugin;
use Throwable;

/**
 * Generates every icon the manifest, iOS and the browser tab need, from one source image.
 *
 * Asking somebody to upload twelve icons is asking for eight, and the four that are missing are
 * always the ones a specific device wanted. So there is one input — a square image, 512px or
 * larger — and everything else is derived.
 *
 * Three kinds come out of it, and the difference between them is the part people get wrong:
 *
 * - **Any** icons keep their transparency and are drawn as-is. What Chrome shows in the install
 *   dialog.
 * - **Maskable** icons are cropped by the launcher to whatever shape the OS prefers — a circle, a
 *   squircle, a rounded rectangle — so the artwork is inset to the 80% safe zone and the rest is
 *   filled with the background colour. A logo that fills its canvas loses its edges without this,
 *   and the symptom is a home screen icon with the sides shaved off.
 * - **Apple touch** icons are flattened onto the background colour, because iOS composites
 *   transparency onto black and a dark logo disappears into it.
 *
 * Regeneration is decided by a stamp file rather than a timestamp: the inputs that matter are the
 * source asset, its own modification date, the maskable override and the background colour, and
 * comparing the four of them is both cheaper and more honest than watching the filesystem.
 */
class Icons extends Component
{
    /** Sizes generated for the `any` purpose. Everything a manifest, a tab or a launcher asks for. */
    public const SIZES = [48, 72, 96, 128, 144, 152, 167, 180, 192, 256, 384, 512];

    /** The two the install criteria actually care about, generated maskable as well. */
    public const MASKABLE_SIZES = [192, 512];

    public const APPLE_SIZE = 180;

    /**
     * Portrait device profiles iOS splash screens are generated for.
     *
     * Safari needs an exact pixel match against a media query or it shows nothing, so this is a
     * list of real devices rather than a range. Each entry is [width, height, dpr] in device
     * pixels; the media query divides by the ratio to get CSS pixels.
     */
    public const SPLASH_DEVICES = [
        [2048, 2732, 2], // iPad Pro 12.9"
        [1668, 2388, 2], // iPad Pro 11"
        [1640, 2360, 2], // iPad Air
        [1620, 2160, 2], // iPad 10.2"
        [1536, 2048, 2], // iPad 9.7"
        [1290, 2796, 3], // iPhone Pro Max
        [1179, 2556, 3], // iPhone Pro
        [1284, 2778, 3], // iPhone Plus
        [1170, 2532, 3], // iPhone
        [1125, 2436, 3], // iPhone X / XS / 11 Pro
        [828, 1792, 2],  // iPhone XR / 11
        [750, 1334, 2],  // iPhone 8 / SE 2
    ];

    /**
     * Everything the manifest should list for a site.
     *
     * Returns [] when no source image has been chosen, which is a state the preflight check
     * reports rather than one this method papers over with a placeholder.
     *
     * @return array<int, array<string, mixed>>
     */
    public function manifestIcons(Manifest $manifest): array
    {
        $siteUid = $manifest->siteUid;

        // The ID, not the asset: this runs for anonymous visitors, who may not be allowed to query
        // the volume the source lives in. It was validated as an image when the manifest was saved.
        if ($siteUid === null || $manifest->iconAssetId === null) {
            return [];
        }

        $icons = [];

        foreach (self::SIZES as $size) {
            $relative = $this->relativePath($siteUid, "icon-{$size}.png");

            if (!Files::exists($relative)) {
                continue;
            }

            $icons[] = [
                'src' => Files::url($relative, $this->siteId($siteUid)),
                'sizes' => "{$size}x{$size}",
                'type' => 'image/png',
                'purpose' => 'any',
            ];
        }

        foreach (self::MASKABLE_SIZES as $size) {
            $relative = $this->relativePath($siteUid, "maskable-{$size}.png");

            if (!Files::exists($relative)) {
                continue;
            }

            $icons[] = [
                'src' => Files::url($relative, $this->siteId($siteUid)),
                'sizes' => "{$size}x{$size}",
                'type' => 'image/png',
                'purpose' => 'maskable',
            ];
        }

        return $icons;
    }

    /** URL of one generated icon, or null if it has not been generated. */
    public function iconUrl(string $siteUid, string $filename): ?string
    {
        $relative = $this->relativePath($siteUid, $filename);

        return Files::exists($relative) ? Files::url($relative, $this->siteId($siteUid)) : null;
    }

    /**
     * iOS splash screens, as the link tags need them.
     *
     * @return array<int, array{url: string, media: string}>
     */
    public function splashLinks(string $siteUid): array
    {
        $links = [];

        foreach (self::SPLASH_DEVICES as [$width, $height, $ratio]) {
            foreach (['portrait' => [$width, $height], 'landscape' => [$height, $width]] as $orientation => [$w, $h]) {
                $relative = $this->relativePath($siteUid, "splash-{$w}x{$h}.png");

                if (!Files::exists($relative)) {
                    continue;
                }

                $links[] = [
                    'url' => Files::url($relative, $this->siteId($siteUid)),
                    'media' => sprintf(
                        '(device-width: %spx) and (device-height: %spx) and (-webkit-device-pixel-ratio: %s) and (orientation: %s)',
                        (int)round($width / $ratio),
                        (int)round($height / $ratio),
                        $ratio,
                        $orientation,
                    ),
                ];
            }
        }

        return $links;
    }

    /** Whether the generated set is out of date with respect to its inputs. */
    public function needsGenerating(Manifest $manifest): bool
    {
        if ($manifest->siteUid === null || $manifest->getSourceAsset() === null) {
            return false;
        }

        $stampPath = Files::path($this->relativePath($manifest->siteUid, '.stamp'));

        if (!is_file($stampPath)) {
            return true;
        }

        return trim((string)file_get_contents($stampPath)) !== $this->stamp($manifest);
    }

    /**
     * Generates the whole set for one site.
     *
     * Returns the number of files written. Throws nothing: an icon set that fails to build must
     * not take a settings save down with it, so failures are logged and reported by preflight,
     * where there is room to say what went wrong.
     */
    public function generate(Manifest $manifest, bool $includeSplash = true): int
    {
        $siteUid = $manifest->siteUid;
        $source = $manifest->getSourceAsset();

        if ($siteUid === null || $source === null) {
            return 0;
        }

        try {
            $sourcePath = $this->localCopy($source);
        } catch (Throwable $e) {
            Plugin::error('Could not read the icon source image: ' . $e->getMessage());
            return 0;
        }

        if ($sourcePath === null) {
            return 0;
        }

        // Asset copies land in Craft's temp directory and are ours to remove, however this ends.
        $temporary = [$sourcePath];

        $dir = $this->relativePath($siteUid, '');
        Files::clear($dir);
        FileHelper::createDirectory(Files::path($dir));

        $written = 0;
        $imagine = $this->imagine();

        try {
            $base = $imagine->open($sourcePath);

            foreach (self::SIZES as $size) {
                $this->write($this->fit($imagine, $base, $size, null), $siteUid, "icon-{$size}.png");
                $written++;
            }

            // Maskable artwork can be its own asset — a version somebody has already padded — and
            // falls back to insetting the main one.
            $maskableSource = $manifest->getMaskableAsset();
            $maskableBase = $base;
            $inset = 0.8;

            if ($maskableSource !== null) {
                $maskablePath = $this->localCopy($maskableSource);

                if ($maskablePath !== null) {
                    $temporary[] = $maskablePath;
                    $maskableBase = $imagine->open($maskablePath);
                    // A purpose-made maskable icon already has its own safe zone.
                    $inset = 1.0;
                }
            }

            foreach (self::MASKABLE_SIZES as $size) {
                $image = $this->fit($imagine, $maskableBase, $size, $manifest->backgroundColor, $inset);
                $this->write($image, $siteUid, "maskable-{$size}.png");
                $written++;
            }

            $this->write(
                $this->fit($imagine, $base, self::APPLE_SIZE, $manifest->backgroundColor),
                $siteUid,
                'apple-touch-icon.png',
            );
            $written++;

            foreach ([16, 32] as $size) {
                $this->write($this->fit($imagine, $base, $size, null), $siteUid, "favicon-{$size}.png");
                $written++;
            }

            if ($includeSplash) {
                $written += $this->generateSplash($imagine, $base, $manifest);
            }
        } catch (Throwable $e) {
            Plugin::error('Icon generation failed: ' . $e->getMessage());
            return $written;
        } finally {
            foreach ($temporary as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }

        file_put_contents(Files::path($this->relativePath($siteUid, '.stamp')), $this->stamp($manifest));

        Plugin::info("Generated {$written} icon files for site {$siteUid}.");

        return $written;
    }

    /**
     * Splash screens, which exist entirely because iOS will not derive one.
     *
     * Every other platform paints `background_color` and centres the icon itself. Safari shows a
     * white flash unless it is handed a pixel-exact image per device and orientation, so that is
     * what gets generated — the icon at 30% of the shorter edge, centred on the background colour.
     */
    private function generateSplash(ImagineInterface $imagine, ImageInterface $base, Manifest $manifest): int
    {
        $siteUid = (string)$manifest->siteUid;
        $written = 0;

        foreach (self::SPLASH_DEVICES as [$width, $height, $ratio]) {
            foreach ([[$width, $height], [$height, $width]] as [$w, $h]) {
                $canvas = $imagine->create(
                    new Box($w, $h),
                    (new RGB())->color($this->hex($manifest->backgroundColor), 100),
                );

                $logo = (int)round(min($w, $h) * 0.3);
                $icon = $base->copy()->resize(new Box($logo, $logo));

                $canvas->paste($icon, new Point((int)round(($w - $logo) / 2), (int)round(($h - $logo) / 2)));

                $this->write($canvas, $siteUid, "splash-{$w}x{$h}.png");
                $written++;
            }
        }

        return $written;
    }

    /**
     * Squares an image at a given size.
     *
     * `$background` null keeps transparency; a colour flattens onto it. `$inset` shrinks the
     * artwork within the square, which is how the maskable safe zone is produced.
     */
    private function fit(ImagineInterface $imagine, ImageInterface $source, int $size, ?string $background, float $inset = 1.0): ImageInterface
    {
        $palette = new RGB();
        $canvasColor = $background !== null
            ? $palette->color($this->hex($background), 100)
            : $palette->color('#ffffff', 0);

        $canvas = $imagine->create(new Box($size, $size), $canvasColor);

        $inner = (int)max(1, round($size * $inset));
        $box = $source->getSize();

        // Contain rather than cover: a logo that is not quite square must not have a slice taken
        // off it to make it one.
        $scale = min($inner / max($box->getWidth(), 1), $inner / max($box->getHeight(), 1));
        $w = (int)max(1, round($box->getWidth() * $scale));
        $h = (int)max(1, round($box->getHeight() * $scale));

        $resized = $source->copy()->resize(new Box($w, $h));
        $canvas->paste($resized, new Point((int)round(($size - $w) / 2), (int)round(($size - $h) / 2)));

        return $canvas;
    }

    private function write(ImageInterface $image, string $siteUid, string $filename): void
    {
        $path = Files::path($this->relativePath($siteUid, $filename));
        FileHelper::createDirectory(dirname($path));

        $image->save($path, ['format' => 'png']);
    }

    /**
     * A local, readable copy of an asset — which for a remote volume means downloading it.
     *
     * Raster sources only. An SVG is refused whatever the image driver: GD cannot draw one at all
     * (the failure is a blank PNG, not an exception), and handing a document that can carry script
     * and external references to Imagick is not worth it for a file about to become PNGs. The
     * manifest screen refuses one too; this catches a source that was set some other way.
     */
    private function localCopy(Asset $asset): ?string
    {
        if (!Manifest::isUsableIconExtension($asset->getExtension())) {
            Plugin::error('The icon source is a .' . $asset->getExtension() . ' file. Use a PNG, JPEG or WebP image.');
            return null;
        }

        return $asset->getCopyOfFile();
    }

    private function imagine(): ImagineInterface
    {
        return Craft::$app->getImages()->getIsImagick() ? new ImagickImagine() : new GdImagine();
    }

    private function hex(string $color): string
    {
        $color = trim($color);

        return preg_match('/^#[0-9a-f]{3}(?:[0-9a-f]{3})?$/i', $color) === 1 ? $color : '#ffffff';
    }

    /**
     * Where one site's files live, under the plugin's directory.
     *
     * The UID is asserted rather than trusted. It becomes a directory name, and the directory is
     * cleared on every regeneration — so a value that is anything other than a UID is refused
     * here, before it is ever joined to a path.
     */
    private function relativePath(string $siteUid, string $filename): string
    {
        if (!StringHelper::isUUID($siteUid)) {
            throw new InvalidArgumentException('Not a site UID: ' . $siteUid);
        }

        if ($filename !== '' && (str_contains($filename, '/') || str_contains($filename, '\\') || str_contains($filename, '..'))) {
            throw new InvalidArgumentException('Not a file name: ' . $filename);
        }

        return 'icons/' . $siteUid . ($filename !== '' ? '/' . $filename : '');
    }

    private function stamp(Manifest $manifest): string
    {
        $source = $manifest->getSourceAsset();
        $maskable = $manifest->getMaskableAsset();

        return sha1(implode('|', [
            (string)$manifest->iconAssetId,
            (string)$source?->dateModified?->getTimestamp(),
            (string)$manifest->maskableAssetId,
            (string)$maskable?->dateModified?->getTimestamp(),
            $manifest->backgroundColor,
            implode(',', self::SIZES),
        ]));
    }

    private function siteId(string $siteUid): ?int
    {
        return Sites::byUid($siteUid)?->id;
    }
}
