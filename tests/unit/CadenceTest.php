<?php

namespace justinholtweb\pwa\tests\unit;

use DateTimeImmutable;
use justinholtweb\pwa\helpers\Cadence;
use PHPUnit\Framework\TestCase;

/**
 * The schedule arithmetic.
 *
 * All of it is pure, and all of it is the kind of thing that looks obviously right and is quietly
 * wrong on one day in seven. The cases that matter are the boundaries: the hour it fires, the day
 * it rolls over, and the week it wraps.
 */
class CadenceTest extends TestCase
{
    private function at(string $when): DateTimeImmutable
    {
        return new DateTimeImmutable($when);
    }

    public function testDailyBeforeTheHourUsesYesterday(): void
    {
        $last = Cadence::lastOccurrence(Cadence::DAILY, 3, 1, $this->at('2026-08-18 02:59:00'));

        self::assertSame('2026-08-17 03:00:00', $last->format('Y-m-d H:i:s'));
    }

    public function testDailyOnTheHourUsesToday(): void
    {
        $last = Cadence::lastOccurrence(Cadence::DAILY, 3, 1, $this->at('2026-08-18 03:00:00'));

        self::assertSame('2026-08-18 03:00:00', $last->format('Y-m-d H:i:s'));
    }

    public function testWeeklyWalksBackToTheChosenWeekday(): void
    {
        // 2026-08-18 is a Tuesday. Asking for Monday must reach yesterday, not six days on.
        $last = Cadence::lastOccurrence(Cadence::WEEKLY, 3, 1, $this->at('2026-08-18 10:00:00'));

        self::assertSame('2026-08-17 03:00:00', $last->format('Y-m-d H:i:s'));
    }

    public function testWeeklyOnTheDayButBeforeTheHourUsesLastWeek(): void
    {
        $last = Cadence::lastOccurrence(Cadence::WEEKLY, 3, 2, $this->at('2026-08-18 01:00:00'));

        self::assertSame('2026-08-11 03:00:00', $last->format('Y-m-d H:i:s'));
    }

    public function testWeeklyWrapsBackwardsAcrossTheWeekend(): void
    {
        // Sunday (7) from a Tuesday is two days back, not five forward.
        $last = Cadence::lastOccurrence(Cadence::WEEKLY, 3, 7, $this->at('2026-08-18 10:00:00'));

        self::assertSame('2026-08-16 03:00:00', $last->format('Y-m-d H:i:s'));
    }

    public function testNeverRunIsAlwaysDue(): void
    {
        self::assertTrue(Cadence::isDue(Cadence::DAILY, 3, 1, null, $this->at('2026-08-18 10:00:00')));
    }

    public function testARunAfterTheOccurrenceIsNotDueAgain(): void
    {
        self::assertFalse(Cadence::isDue(
            Cadence::DAILY,
            3,
            1,
            $this->at('2026-08-18 03:04:00'),
            $this->at('2026-08-18 10:00:00'),
        ));
    }

    public function testARunBeforeTheOccurrenceIsDue(): void
    {
        self::assertTrue(Cadence::isDue(
            Cadence::DAILY,
            3,
            1,
            $this->at('2026-08-17 03:04:00'),
            $this->at('2026-08-18 10:00:00'),
        ));
    }

    public function testALateRunDoesNotPushTheNextOneLater(): void
    {
        // The point of occurrence-based scheduling: yesterday's run happening at 09:00 must not
        // move today's 03:00 slot. Elapsed-time scheduling drifts; this must not.
        $lateRun = $this->at('2026-08-17 09:30:00');

        self::assertTrue(Cadence::isDue(Cadence::DAILY, 3, 1, $lateRun, $this->at('2026-08-18 03:01:00')));
    }

    public function testHoursOutsideTheDayAreClamped(): void
    {
        $last = Cadence::lastOccurrence(Cadence::DAILY, 99, 1, $this->at('2026-08-18 23:59:00'));

        self::assertSame('23', $last->format('H'));
    }
}
