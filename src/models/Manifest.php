<?php

namespace justinholtweb\pwa\models;

use Craft;
use craft\base\Model;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use craft\models\Site;
use justinholtweb\pwa\helpers\Sites;

/**
 * One site's web app manifest.
 *
 * The manifest is the passenger manifest of the whole exercise: it is the document the browser
 * reads to decide whether this site is an app, what to call it, and what to draw on the home
 * screen. Almost every "why won't it offer to install?" question is answered somewhere in here,
 * which is why {@see \justinholtweb\pwa\services\Preflight} checks the rendered output rather than
 * the settings that produced it.
 *
 * Empty fields inherit from the site: a manifest that has never been touched still describes the
 * site correctly, just without any of the choices only a human can make.
 */
class Manifest extends Model
{
    public const DISPLAY_STANDALONE = 'standalone';
    public const DISPLAY_FULLSCREEN = 'fullscreen';
    public const DISPLAY_MINIMAL_UI = 'minimal-ui';
    public const DISPLAY_BROWSER = 'browser';

    /**
     * What an icon source may be. Raster only: SVG is refused outright rather than rasterised,
     * because GD cannot draw one at all and an SVG is a document that can carry script — neither
     * is worth it for a file that is going to be turned into PNGs anyway.
     */
    public const ICON_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp'];

    /** The site this manifest belongs to. */
    public ?string $siteUid = null;

    /** Full app name. Falls back to the site name. */
    public string $name = '';

    /**
     * Home-screen name. Falls back to the full name.
     *
     * Twelve characters is where Android starts truncating; the settings screen says so rather
     * than enforcing it, because a name that reads well truncated is a judgement call.
     */
    public string $shortName = '';

    public string $description = '';

    /** Where a launch from the home screen lands. Relative to the site's base URL. */
    public string $startUrl = '/';

    /**
     * The URL space the app covers. Everything outside it opens in a browser tab instead.
     *
     * Defaults to the site's base path, which for a site in a subdirectory is not `/`.
     */
    public string $scope = '';

    public string $display = self::DISPLAY_STANDALONE;

    /**
     * Ordered fallbacks for `display`.
     *
     * `window-controls-overlay` and `tabbed` are only honoured on desktop, and browsers that do
     * not know a value skip it, so listing the ambitious one first costs nothing.
     *
     * @var string[]
     */
    public array $displayOverride = [];

    public string $orientation = 'any';

    /** The colour the OS paints the title bar and task switcher with. */
    public string $themeColor = '#111827';

    /** What the OS shows while the app is starting — so it should match the page background. */
    public string $backgroundColor = '#ffffff';

    public string $lang = '';
    public string $dir = 'auto';

    /** @var string[] */
    public array $categories = [];

    /**
     * Source image the icon set is generated from.
     *
     * One square image, 512px or larger. Everything the manifest lists — 192, 512, the maskable
     * variant with its safe-zone padding, the Apple touch icon — is derived from it, because the
     * alternative is asking somebody to upload eight files and getting six.
     */
    public ?int $iconAssetId = null;

    /**
     * Optional separate artwork for the maskable icon.
     *
     * Maskable icons are cropped to whatever shape the launcher uses, so a logo that fills its
     * canvas loses its edges. Setting this lets a pre-padded version be used instead of the
     * automatic padding.
     */
    public ?int $maskableAssetId = null;

    /**
     * How the two images are kept in project config. Element IDs differ between environments, so
     * an ID synced from development points at nothing — or at some other asset — in production.
     * The IDs above are resolved from these on load and are what the rest of the code reads.
     */
    public ?string $iconAssetUid = null;
    public ?string $maskableAssetUid = null;

    /**
     * App shortcuts — the long-press menu on the home screen icon.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $shortcuts = [];

    /**
     * Screenshots, which is what makes the richer install dialog appear on Chrome.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $screenshots = [];

    /**
     * Stable identity for the app, so changing `start_url` does not create a second installed app.
     *
     * Defaults to the scope, which is what browsers assume anyway.
     */
    public string $id = '';

    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['siteUid'], 'validateSite', 'skipOnEmpty' => false],
            [['name', 'shortName', 'description', 'startUrl', 'scope', 'id'], 'string'],
            [['startUrl', 'scope', 'id'], 'validateSameOrigin'],
            [['iconAssetId', 'maskableAssetId'], 'validateIconAsset'],
            [['display'], 'in', 'range' => [
                self::DISPLAY_STANDALONE,
                self::DISPLAY_FULLSCREEN,
                self::DISPLAY_MINIMAL_UI,
                self::DISPLAY_BROWSER,
            ]],
            [['orientation'], 'in', 'range' => [
                'any', 'natural', 'portrait', 'landscape',
                'portrait-primary', 'portrait-secondary',
                'landscape-primary', 'landscape-secondary',
            ]],
            [['dir'], 'in', 'range' => ['auto', 'ltr', 'rtl']],
            [
                ['themeColor', 'backgroundColor'],
                'match',
                'pattern' => '/^#[0-9a-f]{3}(?:[0-9a-f]{3})?$/i',
                'message' => Craft::t('pwa', 'Use a hex colour, like #0b3d91.'),
                'skipOnEmpty' => true,
            ],
        ]);
    }

    /** The manifest must belong to a site that exists. */
    public function validateSite(string $attribute): void
    {
        if ($this->siteUid === null || Sites::byUid($this->siteUid) === null) {
            $this->addError($attribute, Craft::t('pwa', 'That site does not exist.'));
        }
    }

    /**
     * Start URL, scope and ID must stay on the site's own origin.
     *
     * A browser discards a manifest whose start URL is cross-origin, so such a value is always a
     * mistake — and preflight fetches whatever is here, so it must not be a way to point the server
     * at somewhere else. Relative values are what everybody types and are always fine.
     */
    public function validateSameOrigin(string $attribute): void
    {
        $value = trim((string)$this->$attribute);

        if ($value === '' || (!str_contains($value, '://') && !str_starts_with($value, '//'))) {
            return;
        }

        $base = (string)App::parseEnv((string)$this->getSite()?->getBaseUrl());

        if (!self::isSameOrigin($value, $base)) {
            $this->addError($attribute, Craft::t('pwa', 'Use a path on this site, like /, rather than a URL on another origin.'));
        }
    }

    /** Icon sources must be raster images. */
    public function validateIconAsset(string $attribute): void
    {
        if (!$this->$attribute) {
            return;
        }

        $asset = Craft::$app->getAssets()->getAssetById((int)$this->$attribute);

        if ($asset === null || $asset->kind !== Asset::KIND_IMAGE || !self::isUsableIconExtension($asset->getExtension())) {
            $this->addError($attribute, Craft::t('pwa', 'Choose a PNG, JPEG or WebP image. SVG and other formats cannot be used as an icon source.'));
        }
    }

    public static function isUsableIconExtension(string $extension): bool
    {
        return in_array(strtolower($extension), self::ICON_EXTENSIONS, true);
    }

    /**
     * Whether an absolute URL shares scheme, host and port with a base URL.
     *
     * A base URL without a host (a bare path) has no origin to compare against, so nothing
     * absolute matches it.
     */
    public static function isSameOrigin(string $url, string $baseUrl): bool
    {
        $url = str_starts_with($url, '//') ? 'https:' . $url : $url;
        $a = parse_url($url);
        $b = parse_url($baseUrl);

        if (!is_array($a) || !is_array($b) || empty($a['host']) || empty($b['host'])) {
            return false;
        }

        $scheme = static fn(array $p) => strtolower($p['scheme'] ?? 'https');
        $port = static fn(array $p) => (int)($p['port'] ?? ($scheme($p) === 'http' ? 80 : 443));

        return $scheme($a) === $scheme($b)
            && strtolower($a['host']) === strtolower($b['host'])
            && $port($a) === $port($b);
    }

    /**
     * A colour as the manifest wants it: `#` and hex digits.
     *
     * Craft's colour field submits `1b8ef2`, not `#1b8ef2`, and a model that validated the
     * posted value as-is would reject every colour saved through the control panel.
     */
    public static function normalizeColor(string $value): string
    {
        $value = trim($value);

        return $value === '' ? '' : '#' . ltrim($value, '#');
    }

    public function getSite(): ?Site
    {
        if ($this->siteUid === null) {
            return Craft::$app->getSites()->getCurrentSite();
        }

        return Sites::byUid($this->siteUid);
    }

    /** The name a browser will actually show. */
    public function getEffectiveName(): string
    {
        $name = trim($this->name);

        if ($name !== '') {
            return $name;
        }

        return (string)($this->getSite()?->getName() ?? Craft::$app->getSystemName());
    }

    public function getEffectiveShortName(): string
    {
        $short = trim($this->shortName);

        return $short !== '' ? $short : $this->getEffectiveName();
    }

    /**
     * The scope, resolved to a path.
     *
     * A site mounted at `/de/` has a scope of `/de/` and not `/`, and getting this wrong is
     * subtle: the app installs, and then every internal link quietly opens a browser tab.
     */
    public function getEffectiveScope(): string
    {
        $scope = trim($this->scope);

        if ($scope === '') {
            $baseUrl = $this->getSite()?->getBaseUrl();
            $scope = $baseUrl ? (parse_url(App::parseEnv($baseUrl) ?: '/', PHP_URL_PATH) ?: '/') : '/';
        }

        $scope = '/' . trim($scope, '/');

        return $scope === '/' ? '/' : $scope . '/';
    }

    public function getEffectiveStartUrl(): string
    {
        $start = trim($this->startUrl);

        if ($start === '' || $start === '/') {
            return $this->getEffectiveScope();
        }

        if (str_contains($start, '://')) {
            return $start;
        }

        return '/' . ltrim($start, '/');
    }

    public function getEffectiveId(): string
    {
        $id = trim($this->id);

        return $id !== '' ? $id : $this->getEffectiveScope();
    }

    public function getEffectiveLang(): string
    {
        $lang = trim($this->lang);

        return $lang !== '' ? $lang : (string)($this->getSite()->language ?? Craft::$app->language);
    }

    public function init(): void
    {
        parent::init();

        // Project config holds UIDs; an older config, or the CP form, holds IDs. Resolved straight
        // from the elements table rather than an asset query: the manifest is served to anonymous
        // visitors, and a plugin that restricts asset queries for guests (protecting the volume the
        // icon happens to live in) would otherwise strip every icon from it.
        foreach (['icon', 'maskable'] as $which) {
            $uid = $this->{$which . 'AssetUid'};

            if ($uid) {
                $id = (new Query())
                    ->select(['id'])
                    ->from(Table::ELEMENTS)
                    ->where(['uid' => $uid, 'type' => Asset::class, 'dateDeleted' => null])
                    ->scalar();
                $this->{$which . 'AssetId'} = $id !== false && $id !== null ? (int)$id : null;
            }
        }
    }

    /**
     * The UIDs to store for the IDs currently set.
     *
     * @return array{iconAssetUid: string|null, maskableAssetUid: string|null}
     */
    public function assetUids(): array
    {
        $uid = static fn(?int $id) => $id ? Asset::find()->id($id)->status(null)->site('*')->one()?->uid : null;

        return [
            'iconAssetUid' => $uid($this->iconAssetId),
            'maskableAssetUid' => $uid($this->maskableAssetId),
        ];
    }

    public function getSourceAsset(): ?Asset
    {
        return $this->iconAssetId ? Craft::$app->getAssets()->getAssetById($this->iconAssetId) : null;
    }

    public function getMaskableAsset(): ?Asset
    {
        return $this->maskableAssetId ? Craft::$app->getAssets()->getAssetById($this->maskableAssetId) : null;
    }

    /**
     * The manifest as the browser receives it, minus the icons.
     *
     * Icons are added by {@see \justinholtweb\pwa\services\Manifests} because they depend on files
     * that have to exist on disk, and a model that reaches for the filesystem to describe itself is
     * a model that cannot be unit tested.
     *
     * @return array<string, mixed>
     */
    public function toManifestArray(): array
    {
        $manifest = [
            'id' => $this->getEffectiveId(),
            'name' => $this->getEffectiveName(),
            'short_name' => $this->getEffectiveShortName(),
            'start_url' => $this->getEffectiveStartUrl(),
            'scope' => $this->getEffectiveScope(),
            'display' => $this->display,
            'orientation' => $this->orientation,
            'theme_color' => $this->themeColor,
            'background_color' => $this->backgroundColor,
            'lang' => $this->getEffectiveLang(),
            'dir' => $this->dir,
        ];

        if (trim($this->description) !== '') {
            $manifest['description'] = trim($this->description);
        }

        if (!empty($this->displayOverride)) {
            $manifest['display_override'] = array_values($this->displayOverride);
        }

        if (!empty($this->categories)) {
            $manifest['categories'] = array_values(array_map('strval', $this->categories));
        }

        $shortcuts = $this->renderShortcuts();

        if (!empty($shortcuts)) {
            $manifest['shortcuts'] = $shortcuts;
        }

        return $manifest;
    }

    /** @return array<int, array<string, mixed>> */
    private function renderShortcuts(): array
    {
        $out = [];

        foreach ($this->shortcuts as $shortcut) {
            $name = trim((string)($shortcut['name'] ?? ''));
            $url = trim((string)($shortcut['url'] ?? ''));

            if ($name === '' || $url === '') {
                continue;
            }

            $entry = [
                'name' => $name,
                'url' => str_contains($url, '://') ? $url : UrlHelper::siteUrl($url, null, null, $this->getSite()?->id),
            ];

            if (trim((string)($shortcut['shortName'] ?? '')) !== '') {
                $entry['short_name'] = trim((string)$shortcut['shortName']);
            }

            if (trim((string)($shortcut['description'] ?? '')) !== '') {
                $entry['description'] = trim((string)$shortcut['description']);
            }

            $out[] = $entry;
        }

        return $out;
    }
}
