<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\FindingSeverityFilter;
use App\Intelligence\Application\StepFindingSummary;
use PHPUnit\Framework\TestCase;

class StepFindingSummaryTest extends TestCase
{
    public function testNotComputedYieldsNotCalculated(): void
    {
        $summary = StepFindingSummary::fromSeverities('01', [FindingSeverityFilter::CRITICAL], false);

        self::assertSame(FindingSeverityFilter::NOT_CALCULATED, $summary->status);
        self::assertSame('Nicht berechnet', $summary->label);
        self::assertSame(0, $summary->total);
    }

    public function testNoFindingsYieldsOk(): void
    {
        $summary = StepFindingSummary::fromSeverities('01', [], true);

        self::assertSame(FindingSeverityFilter::OK, $summary->status);
        self::assertSame('OK', $summary->label);
    }

    public function testPicksWorstSeverityAndBuildsCountLabelInRankOrder(): void
    {
        $summary = StepFindingSummary::fromSeverities('01', [
            FindingSeverityFilter::WARNING,
            FindingSeverityFilter::CRITICAL,
            FindingSeverityFilter::WARNING,
            FindingSeverityFilter::TECHNICAL,
        ], true);

        self::assertSame(FindingSeverityFilter::CRITICAL, $summary->status);
        self::assertSame(4, $summary->total);
        self::assertSame('1 Kritisch / 2 Warnung / 1 Technisch', $summary->label);
    }

    public function testUnknownSeverityValuesAreIgnored(): void
    {
        $summary = StepFindingSummary::fromSeverities('01', ['bogus'], true);

        self::assertSame(FindingSeverityFilter::OK, $summary->status);
        self::assertSame(0, $summary->total);
    }
}
