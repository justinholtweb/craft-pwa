<?php

namespace justinholtweb\pwa\helpers;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * When the scheduled preflight should have last run.
 *
 * Occurrence-based rather than elapsed-based. "Has it been 24 hours?" drifts: a run that starts a
 * minute late pushes the next one a minute later still, and after a month the 3am check happens at
 * lunchtime. Asking "when was the most recent scheduled moment, and have we run since?" keeps 3am
 * at 3am however late any individual run is.
 *
 * Pure, so it can be tested against the awkward days — the hour it fires, the day it rolls over,
 * the week it wraps — without a database or a clock.
 */
class Cadence
{
    public const DAILY = 'daily';
    public const WEEKLY = 'weekly';

    /**
     * The most recent moment the schedule should have fired, at or before `$now`.
     *
     * @param int $hour 0–23
     * @param int $weekday 1 (Monday) – 7 (Sunday), used by the weekly cadence only
     */
    public static function lastOccurrence(string $cadence, int $hour, int $weekday, ?DateTimeInterface $now = null): DateTimeImmutable
    {
        $now = $now instanceof DateTimeImmutable
            ? $now
            : DateTimeImmutable::createFromInterface($now ?? new DateTimeImmutable());

        $hour = max(0, min(23, $hour));
        $today = $now->setTime($hour, 0, 0);

        if ($cadence === self::WEEKLY) {
            $weekday = max(1, min(7, $weekday));
            $delta = (int)$now->format('N') - $weekday;

            if ($delta < 0) {
                $delta += 7;
            }

            $candidate = $today->modify("-{$delta} days");

            // Landing on the right weekday but before the hour means this week's slot has not
            // arrived yet, so the last one was a week ago.
            return $candidate > $now ? $candidate->modify('-7 days') : $candidate;
        }

        return $today > $now ? $today->modify('-1 day') : $today;
    }

    /** Whether a run is owed, given when one last happened. */
    public static function isDue(string $cadence, int $hour, int $weekday, ?DateTimeInterface $lastRun, ?DateTimeInterface $now = null): bool
    {
        $occurrence = self::lastOccurrence($cadence, $hour, $weekday, $now);

        if ($lastRun === null) {
            return true;
        }

        return DateTimeImmutable::createFromInterface($lastRun) < $occurrence;
    }
}
