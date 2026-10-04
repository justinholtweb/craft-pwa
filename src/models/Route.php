<?php

namespace justinholtweb\pwa\models;

use Craft;
use craft\base\Model;

/**
 * One rule in the flight plan.
 *
 * A rule says: for requests that look like *this*, fetch like *that*. The generated service worker
 * walks the rules in order and the first match wins, so the list reads top to bottom the way a
 * flight plan does — the specific legs first, the catch-all last.
 *
 * The matching is deliberately expressible in both PHP and JavaScript with the same semantics: the
 * worker does the real matching in the browser, and this class does it in PHP so the CP can show
 * which rule a given URL would hit before anybody ships it. Two implementations of one rule is a
 * bug waiting to happen, which is why `matches()` and the worker's `matchRoute()` are covered by
 * the same table of cases in the test suite.
 */
class Route extends Model
{
    // How a request is matched.
    public const MATCH_PATH = 'path';
    public const MATCH_EXTENSION = 'extension';
    public const MATCH_DESTINATION = 'destination';
    public const MATCH_HOST = 'host';

    // How it is then fetched.
    public const STRATEGY_NETWORK_FIRST = 'networkFirst';
    public const STRATEGY_CACHE_FIRST = 'cacheFirst';
    public const STRATEGY_STALE_WHILE_REVALIDATE = 'staleWhileRevalidate';
    public const STRATEGY_NETWORK_ONLY = 'networkOnly';
    public const STRATEGY_CACHE_ONLY = 'cacheOnly';

    // Which cache bucket the response lands in. Buckets exist so limits mean something: one
    // eviction ceiling over pages and images at once evicts the shell to make room for a photo.
    public const CACHE_PAGES = 'pages';
    public const CACHE_ASSETS = 'assets';
    public const CACHE_IMAGES = 'images';

    public string $label = '';
    public string $match = self::MATCH_PATH;
    public string $pattern = '*';
    public string $strategy = self::STRATEGY_NETWORK_FIRST;
    public string $cache = self::CACHE_PAGES;

    /**
     * Seconds `networkFirst` waits before falling back to the cache.
     *
     * The number that decides whether a flaky connection feels like a slow site or an offline one.
     * Zero waits forever, which is what a plain `fetch()` does and is almost never what anybody
     * wants on a mobile network.
     */
    public float $networkTimeout = 3.0;

    /** Whether a match here is served the offline page when everything else fails. */
    public bool $offlineFallback = true;

    public bool $enabled = true;

    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['pattern'], 'required'],
            [['pattern'], 'validatePattern'],
            [['match'], 'in', 'range' => [self::MATCH_PATH, self::MATCH_EXTENSION, self::MATCH_DESTINATION, self::MATCH_HOST]],
            [['strategy'], 'in', 'range' => [
                self::STRATEGY_NETWORK_FIRST,
                self::STRATEGY_CACHE_FIRST,
                self::STRATEGY_STALE_WHILE_REVALIDATE,
                self::STRATEGY_NETWORK_ONLY,
                self::STRATEGY_CACHE_ONLY,
            ]],
            [['cache'], 'in', 'range' => [self::CACHE_PAGES, self::CACHE_ASSETS, self::CACHE_IMAGES]],
            [['networkTimeout'], 'number', 'min' => 0, 'max' => 60],
        ]);
    }

    /** Longest pattern a rule may have. Patterns are compiled to regexes on every request. */
    public const MAX_PATTERN_LENGTH = 200;

    /** Most wildcards in one pattern — each `*` is a `.*`, and enough of them backtrack badly. */
    public const MAX_WILDCARDS = 8;

    public function validatePattern(string $attribute): void
    {
        $problem = self::patternProblem((string)$this->$attribute);

        if ($problem !== null) {
            $this->addError($attribute, $problem);
        }
    }

    /**
     * Why a pattern would be refused, or null if it is fine.
     *
     * Both matchers turn `*` into `.*` and test every request against every rule — the worker in
     * the visitor's browser, PHP in the control panel. A long pattern of many wildcards is how a
     * flight plan turns into catastrophic backtracking on one unlucky URL, so the shape is capped
     * here rather than trusted.
     */
    public static function patternProblem(string $pattern): ?string
    {
        if (mb_strlen($pattern) > self::MAX_PATTERN_LENGTH) {
            return Craft::t('pwa', 'A pattern can be at most {max} characters.', ['max' => self::MAX_PATTERN_LENGTH]);
        }

        if (substr_count($pattern, '*') > self::MAX_WILDCARDS) {
            return Craft::t('pwa', 'A pattern can have at most {max} wildcards.', ['max' => self::MAX_WILDCARDS]);
        }

        return null;
    }

    /**
     * The rules a site gets when nobody has written any.
     *
     * These are the whole of Lite's flight plan, and they are chosen to be boring on purpose. A
     * default that caches aggressively makes a demo look fast and makes a real site look broken
     * three days later, so pages go to the network first and only fall back to what was stored.
     *
     * The two `networkOnly` rules at the top are not optional. Caching the control panel means
     * serving a logged-out shell to somebody who is logged in; caching `/actions` means replaying
     * a form post from a cache. Both are put beyond reach rather than left to a checkbox.
     *
     * @return Route[]
     */
    public static function defaults(): array
    {
        $cpTrigger = trim((string)Craft::$app->getConfig()->getGeneral()->cpTrigger, '/') ?: 'admin';

        return [
            new self([
                'label' => Craft::t('pwa', 'Control panel'),
                'match' => self::MATCH_PATH,
                'pattern' => '/' . $cpTrigger . '/*',
                'strategy' => self::STRATEGY_NETWORK_ONLY,
                'offlineFallback' => false,
            ]),
            new self([
                'label' => Craft::t('pwa', 'Craft actions'),
                'match' => self::MATCH_PATH,
                'pattern' => '/actions/*',
                'strategy' => self::STRATEGY_NETWORK_ONLY,
                'offlineFallback' => false,
            ]),
            new self([
                'label' => Craft::t('pwa', 'Images'),
                'match' => self::MATCH_DESTINATION,
                'pattern' => 'image',
                'strategy' => self::STRATEGY_STALE_WHILE_REVALIDATE,
                'cache' => self::CACHE_IMAGES,
                'offlineFallback' => false,
            ]),
            new self([
                'label' => Craft::t('pwa', 'Scripts, styles and fonts'),
                'match' => self::MATCH_DESTINATION,
                'pattern' => 'script,style,font',
                'strategy' => self::STRATEGY_STALE_WHILE_REVALIDATE,
                'cache' => self::CACHE_ASSETS,
                'offlineFallback' => false,
            ]),
            new self([
                'label' => Craft::t('pwa', 'Pages'),
                'match' => self::MATCH_PATH,
                'pattern' => '*',
                'strategy' => self::STRATEGY_NETWORK_FIRST,
                'cache' => self::CACHE_PAGES,
                'networkTimeout' => 3.0,
                'offlineFallback' => true,
            ]),
        ];
    }

    /**
     * Whether this rule claims a request.
     *
     * `$destination` is the browser's own `request.destination` — `document`, `image`, `script`,
     * `style`, `font`, `''`. In PHP it has to be supplied by the caller, which is why the CP's
     * route tester asks for it rather than guessing from the extension: the browser knows what it
     * asked for, and a `.php` endpoint returning an image is not hypothetical.
     */
    public function matches(string $path, string $destination = 'document', string $host = ''): bool
    {
        if (!$this->enabled) {
            return false;
        }

        return match ($this->match) {
            self::MATCH_PATH => $this->anyPatternMatches('/' . ltrim($path, '/')),
            self::MATCH_EXTENSION => in_array(
                strtolower(pathinfo(parse_url($path, PHP_URL_PATH) ?: $path, PATHINFO_EXTENSION)),
                self::patternList($this->pattern),
                true,
            ),
            self::MATCH_DESTINATION => in_array(strtolower($destination), self::patternList($this->pattern), true),
            self::MATCH_HOST => $this->anyPatternMatches($host),
            default => false,
        };
    }

    /** Whether any of this rule's comma-separated alternatives claims the subject. */
    private function anyPatternMatches(string $subject): bool
    {
        foreach (self::patternList($this->pattern) as $pattern) {
            if (self::globMatches($pattern, $subject)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Matches one glob — not a list.
     *
     * The single-pattern contract is deliberate and was arrived at the hard way: the worker's twin
     * of this function receives patterns already split into an array, so a PHP version that also
     * split on commas made the two disagree about a pattern that contained one. Splitting happens
     * in exactly one place on each side now, and the parity test in tests/js covers it.
     *
     * PHP's `fnmatch()` is not used: it is missing on some builds, and its treatment of `/` is not
     * the same as the regex the worker compiles. Both sides translate explicitly instead — `*`
     * spans anything including slashes, `?` is one character, everything else is literal.
     */
    public static function globMatches(string $pattern, string $subject): bool
    {
        $regex = '#^' . str_replace(['\*', '\?'], ['.*', '.'], preg_quote(trim($pattern), '#')) . '$#i';

        return preg_match($regex, $subject) === 1;
    }

    /** @return string[] */
    private static function patternList(string $pattern): array
    {
        return array_values(array_filter(array_map(
            static fn(string $part) => strtolower(trim($part)),
            explode(',', $pattern),
        ), static fn(string $part) => $part !== ''));
    }

    /**
     * The rule as the service worker receives it.
     *
     * @return array<string, mixed>
     */
    public function toWorkerArray(): array
    {
        return [
            'label' => $this->label,
            'match' => $this->match,
            'patterns' => self::patternList($this->pattern),
            'strategy' => $this->strategy,
            'cache' => $this->cache,
            'timeout' => (float)$this->networkTimeout,
            'fallback' => (bool)$this->offlineFallback,
        ];
    }

    public function getStrategyLabel(): string
    {
        return match ($this->strategy) {
            self::STRATEGY_NETWORK_FIRST => Craft::t('pwa', 'Network first'),
            self::STRATEGY_CACHE_FIRST => Craft::t('pwa', 'Cache first'),
            self::STRATEGY_STALE_WHILE_REVALIDATE => Craft::t('pwa', 'Stale while revalidate'),
            self::STRATEGY_NETWORK_ONLY => Craft::t('pwa', 'Network only'),
            self::STRATEGY_CACHE_ONLY => Craft::t('pwa', 'Cache only'),
            default => $this->strategy,
        };
    }
}
