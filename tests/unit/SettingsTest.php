<?php

namespace justinholtweb\pwa\tests\unit;

use justinholtweb\pwa\models\Settings;
use PHPUnit\Framework\TestCase;

/**
 * The pieces of the settings model that decide behaviour rather than store it.
 */
class SettingsTest extends TestCase
{
    public function testNothingIsExcludedByDefault(): void
    {
        self::assertFalse((new Settings())->isExcluded('/anything'));
    }

    public function testAnExactPathIsExcluded(): void
    {
        $settings = new Settings(['injectExclude' => ['/print']]);

        self::assertTrue($settings->isExcluded('/print'));
        self::assertTrue($settings->isExcluded('print'));
        self::assertFalse($settings->isExcluded('/printer'));
    }

    public function testAWildcardExcludesASubtree(): void
    {
        $settings = new Settings(['injectExclude' => ['/embed/*']]);

        self::assertTrue($settings->isExcluded('/embed/widget'));
        self::assertFalse($settings->isExcluded('/embed'));
    }

    public function testExclusionIsCaseInsensitive(): void
    {
        $settings = new Settings(['injectExclude' => ['/Print']]);

        self::assertTrue($settings->isExcluded('/print'));
    }

    public function testEmptyPatternsAreIgnoredRatherThanMatchingEverything(): void
    {
        // A trailing newline in the textarea must not switch injection off site-wide.
        $settings = new Settings(['injectExclude' => ['', '  ']]);

        self::assertFalse($settings->isExcluded('/'));
    }
}
