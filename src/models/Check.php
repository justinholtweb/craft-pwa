<?php

namespace justinholtweb\pwa\models;

use craft\base\Model;

/**
 * One preflight check result.
 *
 * A check is not a boolean. "Your manifest has no 512px icon" is useless on its own; what the
 * person reading it needs is what was looked at, what was found, and the one thing to do next.
 * Every check therefore carries its own remediation, and a check that cannot say what to do about
 * a failure has no business failing.
 *
 * `SKIP` exists so the report can distinguish "this is fine" from "this was never looked at" —
 * push checks on a Lite install, HTTPS on a local machine.
 */
class Check extends Model
{
    public const PASS = 'pass';
    public const WARN = 'warn';
    public const FAIL = 'fail';
    public const SKIP = 'skip';

    // Which part of the aircraft the check belongs to, used to group the report.
    public const GROUP_DELIVERY = 'delivery';
    public const GROUP_MANIFEST = 'manifest';
    public const GROUP_ICONS = 'icons';
    public const GROUP_WORKER = 'worker';
    public const GROUP_OFFLINE = 'offline';
    public const GROUP_PUSH = 'push';

    /** Stable key, so a check can be referenced from docs and compared across audits. */
    public string $key = '';

    public string $group = self::GROUP_MANIFEST;
    public string $label = '';
    public string $status = self::PASS;

    /** What was found, in one sentence. */
    public string $summary = '';

    /** What to do about it. Empty on a pass. */
    public string $remediation = '';

    /** Whatever the check measured, for the detail panel — URLs fetched, sizes, headers. */
    public array $detail = [];

    /**
     * Whether an installable PWA is impossible while this is failing.
     *
     * Browsers have a hard floor for installability — a manifest, a start URL, an icon of at least
     * 192px, a display mode other than `browser`, a service worker with a fetch handler, and a
     * secure origin. Failing any of those is not a suggestion, and the report says so rather than
     * listing it among fourteen other amber rows.
     */
    public bool $blocking = false;

    public static function pass(string $key, string $group, string $label, string $summary, array $detail = []): self
    {
        return new self(compact('key', 'group', 'label', 'summary', 'detail') + ['status' => self::PASS]);
    }

    public static function warn(string $key, string $group, string $label, string $summary, string $remediation, array $detail = []): self
    {
        return new self(compact('key', 'group', 'label', 'summary', 'remediation', 'detail') + ['status' => self::WARN]);
    }

    public static function fail(string $key, string $group, string $label, string $summary, string $remediation, bool $blocking = false, array $detail = []): self
    {
        return new self(compact('key', 'group', 'label', 'summary', 'remediation', 'blocking', 'detail') + ['status' => self::FAIL]);
    }

    public static function skip(string $key, string $group, string $label, string $summary): self
    {
        return new self(compact('key', 'group', 'label', 'summary') + ['status' => self::SKIP]);
    }

    public function isProblem(): bool
    {
        return $this->status === self::FAIL || $this->status === self::WARN;
    }
}
