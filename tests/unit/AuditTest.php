<?php

namespace justinholtweb\pwa\tests\unit;

use justinholtweb\pwa\models\Audit;
use justinholtweb\pwa\models\Check;
use PHPUnit\Framework\TestCase;

/**
 * The scoring.
 *
 * A score is a summary, and a summary that flatters is worse than no summary. The property that
 * matters here is that a site the browser will not install cannot score in the eighties, whatever
 * else it gets right.
 */
class AuditTest extends TestCase
{
    // Named `passing`/`warning`/`failing` rather than the obvious `pass`/`fail`: PHPUnit's
    // Assert::fail() is final, and a helper called fail() is a fatal error at class-load time.
    private function passing(string $key = 'a'): Check
    {
        return Check::pass($key, Check::GROUP_MANIFEST, 'Label', 'Summary');
    }

    private function warning(string $key = 'b'): Check
    {
        return Check::warn($key, Check::GROUP_MANIFEST, 'Label', 'Summary', 'Do this');
    }

    private function failing(string $key = 'c', bool $blocking = false): Check
    {
        return Check::fail($key, Check::GROUP_MANIFEST, 'Label', 'Summary', 'Do this', $blocking);
    }

    public function testAllPassingScoresFullMarks(): void
    {
        $audit = Audit::fromChecks([$this->passing('a'), $this->passing('b'), $this->passing('c')]);

        self::assertSame(100, $audit->score);
        self::assertTrue($audit->installable);
        self::assertSame(3, $audit->passed);
    }

    public function testSkippedChecksDoNotCountAgainstTheScore(): void
    {
        $audit = Audit::fromChecks([
            $this->passing('a'),
            Check::skip('push', Check::GROUP_PUSH, 'Push', 'Pro only'),
        ]);

        self::assertSame(100, $audit->score);
        self::assertSame(1, $audit->skipped);
    }

    public function testAWarningCostsHalfOfAFailure(): void
    {
        $withWarning = Audit::fromChecks([$this->passing('a'), $this->passing('b'), $this->warning('c')]);
        $withFailure = Audit::fromChecks([$this->passing('a'), $this->passing('b'), $this->failing('c')]);

        self::assertGreaterThan($withFailure->score, $withWarning->score);
        self::assertSame(83, $withWarning->score);
        self::assertSame(67, $withFailure->score);
    }

    public function testABlockingFailureDragsAnOtherwisePerfectSiteOutOfTheGreen(): void
    {
        $checks = array_map(fn(int $i) => $this->passing("pass-{$i}"), range(1, 19));
        $checks[] = $this->failing('blocker', true);

        $audit = Audit::fromChecks($checks);

        self::assertFalse($audit->installable);
        self::assertLessThan(90, $audit->score);
        self::assertCount(1, $audit->getBlockers());
    }

    public function testTheScoreNeverGoesBelowZero(): void
    {
        $audit = Audit::fromChecks([$this->failing('a', true), $this->failing('b', true), $this->failing('c', true)]);

        self::assertSame(0, $audit->score);
    }

    public function testAnEmptyAuditScoresZeroRatherThanDividingByZero(): void
    {
        $audit = Audit::fromChecks([]);

        self::assertSame(0, $audit->score);
        self::assertTrue($audit->installable);
    }

    public function testProblemsAreFailuresAndWarningsOnly(): void
    {
        $audit = Audit::fromChecks([
            $this->passing('a'),
            $this->warning('b'),
            $this->failing('c'),
            Check::skip('d', Check::GROUP_PUSH, 'Push', 'Skipped'),
        ]);

        self::assertCount(2, $audit->getProblems());
    }

    public function testANonBlockingFailureStillLeavesTheSiteInstallable(): void
    {
        $audit = Audit::fromChecks([$this->passing('a'), $this->failing('b')]);

        self::assertTrue($audit->installable);
        self::assertSame(1, $audit->failed);
    }
}
