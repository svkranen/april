<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\KpiPeriod;
use App\Intelligence\Application\ProcessKpiAggregator;
use App\Intelligence\Application\ProcessKpiMeasurements;
use App\Intelligence\Infrastructure\Process\InMemoryProcessVersionRepository;
use App\Tests\Fake\InMemoryProcessEventReader;
use App\Tests\Intelligence\Domain\Fixtures\KpiScenario as Scenario;
use PHPUnit\Framework\TestCase;

final class ProcessKpiAggregatorTest extends TestCase
{
    public function testNoDataKeepsStatisticsUnavailableAndTemplateStepsVisible(): void
    {
        $summary = $this->aggregate([]);
        self::assertSame(0, $summary->started);
        self::assertSame(0, $summary->completed);
        self::assertSame(0, $summary->open);
        self::assertNull($summary->e2e->median);
        self::assertCount(5, $summary->steps);
        self::assertSame(0, $summary->steps[0]->visits);
        self::assertNull($summary->steps[0]->duration->median);
        self::assertTrue($summary->isEmpty());
    }

    public function testCompletionWithinPeriodUsesWholeE2eFromBeforePeriod(): void
    {
        $summary = $this->aggregate(Scenario::linear());
        self::assertSame(1, $summary->started);
        self::assertSame(1, $summary->completed);
        self::assertSame(0, $summary->open);
        self::assertSame(1, $summary->e2e->count);
        self::assertSame(7200.0, $summary->e2e->average);
        self::assertSame(7200.0, $summary->e2e->median);
        self::assertSame(7200.0, $summary->e2e->p90);
        self::assertSame(['2026-02-01' => 1], $summary->completions);
        self::assertSame(3600.0, $summary->steps[1]->duration->median);
    }

    public function testMultipleCompletedRunsProduceExpectedStatistics(): void
    {
        $events = [];
        foreach ([1, 2, 3] as $minutes) {
            $events[] = Scenario::event('s'.$minutes, 'start', 'before', '2026-02-01T10:00:00Z', item: 'item-'.$minutes);
            $events[] = Scenario::event('e'.$minutes, 'end', 'after', '2026-02-01T10:0'.$minutes.':00Z', item: 'item-'.$minutes);
        }
        $summary = $this->aggregate($events);
        self::assertSame(3, $summary->started);
        self::assertSame(3, $summary->completed);
        self::assertSame(120.0, $summary->e2e->average);
        self::assertSame(120.0, $summary->e2e->median);
        self::assertSame(180.0, $summary->e2e->p90);
        self::assertSame(180.0, $summary->e2e->maximum);
    }

    public function testMissingStartStillCountsCompletionWithoutAFalseZeroDuration(): void
    {
        $summary = $this->aggregate([Scenario::event('e', 'end', 'after', '2026-02-01T10:00:00Z')]);
        self::assertSame(1, $summary->completed);
        self::assertSame(1, $summary->completedWithoutDuration);
        self::assertSame(0, $summary->e2e->count);
        self::assertNull($summary->e2e->average);
        self::assertSame(1, $summary->reasons['missing_start']);
        self::assertSame(1, $summary->stepVisitsWithoutDuration);
    }

    public function testOpenInventoryIncludesEarlierStartsButNotFutureStarts(): void
    {
        $summary = $this->aggregate([
            Scenario::event('old', 'start', 'before', '2026-01-31T10:00:00Z'),
            Scenario::event('new', 'start', 'before', '2026-02-02T00:00:00Z', item: 'future'),
        ]);
        self::assertSame(0, $summary->started);
        self::assertSame(0, $summary->completed);
        self::assertSame(1, $summary->open);
    }

    public function testHistoricalCutoffExcludesFutureCompletionsAndTheirCoverage(): void
    {
        $summary = $this->aggregate([
            Scenario::event('s', 'start', 'before', '2026-01-31T09:00:00Z'),
            Scenario::event('sa', 'start', 'after', '2026-01-31T09:01:00Z'),
            Scenario::event('ab', 'A', 'before', '2026-01-31T10:00:00Z'),
        ], KpiPeriod::fromDates('2026-01-31', '2026-01-31'));
        self::assertSame(1, $summary->started);
        self::assertSame(1, $summary->open);
        self::assertSame(0, $summary->completed);
        self::assertNull($summary->e2e->median);
        $step = $summary->steps[1];
        self::assertSame(1, $step->open);
        self::assertSame(1, $step->coverage['beforeOnly']);
        self::assertSame(0, $step->coverage['after']);
    }

    public function testRepeatedVisitsPreserveMixedCoverageAndDistinctDurations(): void
    {
        $events = [];
        foreach (['start:before', 'A:after', 'A:before', 'A:after', 'A:before', 'B:unknown', 'end:after'] as $i => $phase) {
            [$step, $phase] = explode(':', $phase);
            $events[] = Scenario::event('e'.$i, $step, $phase, sprintf('2026-02-01T10:%02d:00Z', $i));
        }
        $summary = $this->aggregate($events);
        $a = $summary->steps[1];
        self::assertSame(3, $a->visits);
        self::assertSame(2, $a->completed);
        self::assertSame(1, $a->open);
        self::assertSame(1, $a->duration->count);
        self::assertSame(2, $a->withoutDuration);
        self::assertSame(60.0, $a->duration->median);
        self::assertTrue($a->mixedCoverage);
        self::assertSame(['before' => 2, 'after' => 2, 'both' => 1, 'beforeOnly' => 1, 'afterOnly' => 1, 'neither' => 0], $a->coverage);
        self::assertSame(0, $summary->steps[2]->visits);
        self::assertSame(1, $summary->ambiguousStepFragments);
    }

    public function testAmbiguousRunIsNotCountedAsAConfidentCompletion(): void
    {
        $events = [...Scenario::linear(), Scenario::event('second-start', 'start', 'before', '2026-01-31T23:10:00Z')];
        $summary = $this->aggregate($events);
        self::assertSame(1, $summary->ambiguousRuns);
        self::assertSame(0, $summary->completed);
        self::assertSame(1, $summary->reasons['ambiguous_run']);
    }

    public function testCompletionAtFromMidnightIsCountedAndCompletionAtNextMidnightRemainsOpen(): void
    {
        $summary = $this->aggregate([
            Scenario::event('s1', 'start', 'before', '2026-01-31T23:00:00Z', item: 'one'),
            Scenario::event('e1', 'end', 'after', '2026-02-01T00:00:00Z', item: 'one'),
            Scenario::event('s2', 'start', 'before', '2026-02-01T12:00:00Z', item: 'two'),
            Scenario::event('e2', 'end', 'after', '2026-02-02T00:00:00Z', item: 'two'),
        ]);
        self::assertSame(2, $summary->started);
        self::assertSame(1, $summary->completed);
        self::assertSame(1, $summary->open);
        self::assertSame(3600.0, $summary->e2e->median);
    }

    private function aggregate(array $events, ?KpiPeriod $period = null): \App\Intelligence\Application\ProcessKpiSummary
    {
        $period ??= KpiPeriod::fromDates('2026-02-01', '2026-02-01');
        $measurements = new ProcessKpiMeasurements(new InMemoryProcessEventReader($events), new InMemoryProcessVersionRepository(Scenario::versions()));
        $runs = $measurements->forTemplate(Scenario::template(), Scenario::definition(), observedBefore: $period->until);

        return (new ProcessKpiAggregator())->aggregate(Scenario::template(), $runs, $period);
    }
}
