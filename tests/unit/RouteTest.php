<?php

namespace justinholtweb\pwa\tests\unit;

use justinholtweb\pwa\models\Route;
use PHPUnit\Framework\TestCase;

/**
 * The route matcher.
 *
 * The cases come from a JSON fixture rather than from this file, because the same table is
 * asserted against the JavaScript matcher inside the generated service worker
 * (tests/js/route-parity.mjs). Two implementations of one rule is the risk this plugin has to
 * live with — the browser must match in JS, the control panel's route tester must match in PHP —
 * and a shared table is the only thing that keeps them honest.
 */
class RouteTest extends TestCase
{
    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $json = file_get_contents(dirname(__DIR__) . '/fixtures/route-cases.json');

        self::assertIsString($json, 'The route fixture could not be read.');

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return Route[] */
    private function routes(): array
    {
        return array_map(
            static fn(array $config) => new Route([
                'label' => $config['label'],
                'match' => $config['match'],
                'pattern' => implode(',', $config['patterns']),
                'strategy' => $config['strategy'],
                'cache' => $config['cache'],
                'networkTimeout' => (float)$config['timeout'],
                'offlineFallback' => $config['fallback'],
            ]),
            $this->fixture()['routes'],
        );
    }

    private function firstMatch(string $path, string $destination, string $host): ?int
    {
        foreach ($this->routes() as $index => $route) {
            if ($route->matches($path, $destination, $host)) {
                return $index;
            }
        }

        return null;
    }

    public function testEveryCaseHitsTheExpectedRule(): void
    {
        foreach ($this->fixture()['cases'] as $case) {
            self::assertSame(
                $case['expect'],
                $this->firstMatch($case['path'], $case['destination'], $case['host']),
                $case['why'],
            );
        }
    }

    public function testGlobTable(): void
    {
        foreach ($this->fixture()['globs'] as $case) {
            self::assertSame(
                $case['expect'],
                Route::globMatches($case['pattern'], $case['subject']),
                sprintf('“%s” against “%s”', $case['pattern'], $case['subject']),
            );
        }
    }

    public function testADisabledRuleNeverMatches(): void
    {
        $route = new Route(['match' => Route::MATCH_PATH, 'pattern' => '*', 'enabled' => false]);

        self::assertFalse($route->matches('/anything'));
    }

    public function testGlobMetacharactersInThePatternAreLiteral(): void
    {
        // A pattern is a glob, not a regular expression. Somebody typing a `.` means a dot.
        self::assertFalse(Route::globMatches('/a.b', '/aXb'));
        self::assertTrue(Route::globMatches('/a.b', '/a.b'));
        self::assertFalse(Route::globMatches('/a+', '/aaa'));
    }

    public function testTheWorkerArrayCarriesPatternsAsAList(): void
    {
        $route = new Route([
            'match' => Route::MATCH_DESTINATION,
            'pattern' => 'script, style , font',
            'strategy' => Route::STRATEGY_CACHE_FIRST,
        ]);

        $array = $route->toWorkerArray();

        self::assertSame(['script', 'style', 'font'], $array['patterns']);
        self::assertSame('cacheFirst', $array['strategy']);
    }

    public function testDestinationMatchingIsCaseInsensitive(): void
    {
        $route = new Route(['match' => Route::MATCH_DESTINATION, 'pattern' => 'Image']);

        self::assertTrue($route->matches('/whatever', 'image'));
    }
}
