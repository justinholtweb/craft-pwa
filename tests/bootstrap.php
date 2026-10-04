<?php

/**
 * Bootstrap for the unit suite.
 *
 * These run against plain PHP — no Craft application, no database, no HTTP. What they cover is the
 * part of the plugin that is deliberately pure: the route matcher, the schedule arithmetic, the
 * scoring, and — most importantly — the push encryption, which is checked against the test vector
 * published in RFC 8291.
 *
 * Everything that needs a live Craft is exercised against the plugin-testing harness instead, as
 * described in tests/README.md.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

// The `Craft` and `Yii` facades, without an application. Enough for `Craft::t()` to fall back to
// the source string and for `Plugin::getInstance()` to answer null, which is how the pure guards
// behave outside a request.
require_once dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';
require_once dirname(__DIR__) . '/vendor/craftcms/cms/src/Craft.php';
