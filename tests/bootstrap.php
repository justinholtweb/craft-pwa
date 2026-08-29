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
