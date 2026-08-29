<?php

namespace justinholtweb\pwa\models;

use craft\base\Model;
use DateTime;

/**
 * One preflight run, and its verdict.
 *
 * Kept as a record rather than recomputed on demand for the same reason a maintenance log is:
 * the useful question is rarely "is it right now?" but "when did this stop being right, and what
 * changed that week?"
 */
class Audit extends Model
{
    public ?int $id = null;
    public ?int $siteId = null;

    /** `manual`, `scheduled` or `console`. */
    public string $trigger = 'manual';

    /**
     * 0–100.
     *
     * Not a Lighthouse score and not pretending to be one — it is the proportion of checks that
     * passed, with blocking failures weighted so that a site the browser will not install cannot
     * score in the eighties.
     */
    public int $score = 0;

    public int $passed = 0;
    public int $warned = 0;
    public int $failed = 0;
    public int $skipped = 0;

    /** Whether anything blocking is failing — the only yes/no worth putting on a dashboard. */
    public bool $installable = false;

    /** @var Check[] */
    public array $checks = [];

    public ?DateTime $dateCreated = null;

    /** @return Check[] */
    public function getProblems(): array
    {
        return array_values(array_filter($this->checks, static fn(Check $c) => $c->isProblem()));
    }

    /** @return Check[] */
    public function getBlockers(): array
    {
        return array_values(array_filter(
            $this->checks,
            static fn(Check $c) => $c->blocking && $c->status === Check::FAIL,
        ));
    }

    /**
     * @param Check[] $checks
     */
    public static function fromChecks(array $checks, ?int $siteId = null, string $trigger = 'manual'): self
    {
        $audit = new self(['siteId' => $siteId, 'trigger' => $trigger, 'checks' => array_values($checks)]);

        foreach ($checks as $check) {
            match ($check->status) {
                Check::PASS => $audit->passed++,
                Check::WARN => $audit->warned++,
                Check::FAIL => $audit->failed++,
                default => $audit->skipped++,
            };
        }

        $audit->installable = $audit->getBlockers() === [];

        // Warnings cost half a point, failures the whole one, and a blocking failure costs three —
        // enough that one of them drags a site with everything else right down out of the green.
        $counted = $audit->passed + $audit->warned + $audit->failed;

        if ($counted === 0) {
            $audit->score = 0;
        } else {
            $lost = ($audit->warned * 0.5) + $audit->failed + (count($audit->getBlockers()) * 2);
            $audit->score = (int)max(0, round(100 * (1 - ($lost / max($counted, 1)))));
        }

        return $audit;
    }
}
