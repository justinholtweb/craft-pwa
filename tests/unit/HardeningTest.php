<?php

namespace justinholtweb\pwa\tests\unit;

use justinholtweb\pwa\controllers\SettingsController;
use justinholtweb\pwa\helpers\Files;
use justinholtweb\pwa\helpers\RateLimit;
use justinholtweb\pwa\models\Manifest;
use justinholtweb\pwa\models\Route;
use justinholtweb\pwa\services\Push;
use PHPUnit\Framework\TestCase;

/**
 * The pure halves of the 5.0.0 hardening: each guard that decides whether input is acceptable,
 * checked without a Craft application so a regression shows up in the fast suite.
 */
class HardeningTest extends TestCase
{
    // ------------------------------------------------------------------------ path guard

    public function testAPathInsideTheBaseResolves(): void
    {
        self::assertSame('/srv/web/pwa/icons/abc/icon-192.png', Files::within('/srv/web/pwa', 'icons/abc/icon-192.png'));
        self::assertSame('/srv/web/pwa/icons/abc', Files::within('/srv/web/pwa', '/icons/abc'));
    }

    public function testTraversalOutOfTheBaseIsRefused(): void
    {
        self::assertNull(Files::within('/srv/web/pwa', '../index.php'));
        self::assertNull(Files::within('/srv/web/pwa', 'icons/../..'));
        self::assertNull(Files::within('/srv/web/pwa', 'icons/../../web'));
        self::assertNull(Files::within('/srv/web/pwa', '..\\..\\etc'));
    }

    public function testASiblingDirectoryWithTheSamePrefixIsRefused(): void
    {
        // `/srv/web/pwa-evil` starts with `/srv/web/pwa`; only the separator tells them apart.
        self::assertNull(Files::within('/srv/web/pwa', '../pwa-evil/x'));
    }

    // ------------------------------------------------------------------------ push keys

    public function testRealSubscriptionKeysAreAccepted(): void
    {
        self::assertTrue(Push::validKeys(
            'BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSnfckjBJuBkr3qBUYIHBQFLXYp5Nksh8U',
            'tBHItJI5svbpez7KI4CCXg',
        ));
    }

    public function testMalformedKeysAreRefused(): void
    {
        $p256dh = 'BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSnfckjBJuBkr3qBUYIHBQFLXYp5Nksh8U';
        $auth = 'tBHItJI5svbpez7KI4CCXg';

        self::assertFalse(Push::validKeys('', $auth));
        self::assertFalse(Push::validKeys($p256dh, ''));
        self::assertFalse(Push::validKeys('not base64!', $auth));
        self::assertFalse(Push::validKeys(substr($p256dh, 0, 40), $auth), 'too short');
        // 65 bytes, but a compressed-point prefix rather than 0x04.
        self::assertFalse(Push::validKeys(rtrim(strtr(base64_encode("\x02" . str_repeat('a', 64)), '+/', '-_'), '='), $auth));
        self::assertFalse(Push::validKeys($p256dh, rtrim(strtr(base64_encode(str_repeat('a', 15)), '+/', '-_'), '=')), '15-byte auth');
    }

    public function testAnAddressIsNeverAPushService(): void
    {
        self::assertFalse(Push::isPushEndpoint('https://10.0.0.5/x'));
        self::assertFalse(Push::isPushEndpoint('https://[::1]/x'));
        self::assertTrue(Push::isPushEndpoint('https://fcm.googleapis.com/fcm/send/x'));
    }

    // ------------------------------------------------------------------------ route caps

    public function testOrdinaryPatternsAreFine(): void
    {
        self::assertNull(Route::patternProblem('/blog/*'));
        self::assertNull(Route::patternProblem(str_repeat('a', Route::MAX_PATTERN_LENGTH)));
        self::assertNull(Route::patternProblem(str_repeat('*/', Route::MAX_WILDCARDS)));
    }

    public function testLongOrWildPatternsAreRefused(): void
    {
        self::assertNotNull(Route::patternProblem(str_repeat('a', Route::MAX_PATTERN_LENGTH + 1)));
        self::assertNotNull(Route::patternProblem(str_repeat('*a', Route::MAX_WILDCARDS + 1)));
    }

    // ------------------------------------------------------------------------ manifest

    public function testColoursFromTheColourFieldGainTheirHash(): void
    {
        self::assertSame('#1b8ef2', Manifest::normalizeColor('1b8ef2'));
        self::assertSame('#1b8ef2', Manifest::normalizeColor('#1b8ef2'));
        self::assertSame('#1b8ef2', Manifest::normalizeColor('  ##1b8ef2 '));
        self::assertSame('', Manifest::normalizeColor(''));
    }

    public function testOnlyRasterIconSourcesAreUsable(): void
    {
        self::assertTrue(Manifest::isUsableIconExtension('PNG'));
        self::assertTrue(Manifest::isUsableIconExtension('webp'));
        self::assertFalse(Manifest::isUsableIconExtension('svg'));
        self::assertFalse(Manifest::isUsableIconExtension('gif'));
    }

    public function testSameOriginComparesSchemeHostAndPort(): void
    {
        $base = 'https://example.com/de/';

        self::assertTrue(Manifest::isSameOrigin('https://example.com/start', $base));
        self::assertTrue(Manifest::isSameOrigin('https://EXAMPLE.com:443/', $base));
        self::assertFalse(Manifest::isSameOrigin('http://example.com/', $base));
        self::assertFalse(Manifest::isSameOrigin('https://example.com:8443/', $base));
        self::assertFalse(Manifest::isSameOrigin('https://evil.example/', $base));
        self::assertFalse(Manifest::isSameOrigin('//evil.example/', $base));
        self::assertFalse(Manifest::isSameOrigin('https://example.com/', '/relative-base/'));
    }

    // ------------------------------------------------------------------------ rate limit

    public function testTheConnectingAddressIsTheKeyByDefault(): void
    {
        self::assertSame('203.0.113.9', RateLimit::key('203.0.113.9'));
        self::assertSame('203.0.113.9', RateLimit::key('203.0.113.9', ''));
    }

    public function testAForwardedAddressIsOnlyUsedWhenPassedIn(): void
    {
        self::assertSame('198.51.100.7', RateLimit::key('10.0.0.1', '198.51.100.7'));
    }

    public function testIpv6IsGroupedBySlash64(): void
    {
        $a = RateLimit::key('2001:db8:1234:5678::1');
        $b = RateLimit::key('2001:db8:1234:5678:ffff:ffff:ffff:ffff');
        $c = RateLimit::key('2001:db8:1234:5679::1');

        self::assertSame($a, $b);
        self::assertNotSame($a, $c);
    }

    // ------------------------------------------------------------------------ settings

    public function testASectionOnlyAcceptsItsOwnKeys(): void
    {
        $posted = [
            'promptTitle' => 'Install',
            'extraPushHosts' => ['internal.example'],
            'manifests' => ['x' => []],
            'routes' => [],
            'enabled' => '0',
        ];

        self::assertSame(['promptTitle' => 'Install'], SettingsController::allowedValues('prompt', $posted));
        self::assertSame(['enabled' => '0'], SettingsController::allowedValues('general', $posted));
        self::assertSame([], SettingsController::allowedValues('nonsense', $posted));
    }

    public function testNoSectionCanSetTheConfigFileOnlyKeys(): void
    {
        $everything = array_merge(...array_values(SettingsController::EDITABLE));

        foreach (['extraPushHosts', 'manifests', 'routes', 'logLevel'] as $key) {
            self::assertNotContains($key, $everything, "$key is settable from the CP");
        }
    }
}
