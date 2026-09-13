<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\ProcessKpiMeasurements;
use App\Intelligence\Domain\KpiMeasurementReason;
use App\Intelligence\Domain\ProcessVersion;
use App\Intelligence\Infrastructure\Process\InMemoryProcessVersionRepository;
use App\Tests\Fake\InMemoryProcessEventReader;
use App\Tests\Intelligence\Domain\Fixtures\KpiScenario as Scenario;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ProcessKpiMeasurementsTest extends TestCase
{
    public function testApplicationReturnsReconstructedMeasurementsWithoutAggregation(): void
    {
        $service = new ProcessKpiMeasurements(new InMemoryProcessEventReader(Scenario::linear()), new InMemoryProcessVersionRepository(Scenario::versions()));
        $runs = $service->forTemplate(Scenario::template(), Scenario::definition());
        self::assertCount(1, $runs);
        self::assertSame(7200.0, $runs[0]->e2eDuration->seconds);
        self::assertSame(3600.0, $runs[0]->stepVisits[1]->duration->seconds);
    }

    public function testSelectedVersionRetainsNextBoundaryAndDiagnosticRun(): void
    {
        $service = new ProcessKpiMeasurements(new InMemoryProcessEventReader(Scenario::linear()), new InMemoryProcessVersionRepository([
            ...Scenario::versions(), new ProcessVersion(null, 'review', '2', new DateTimeImmutable('2026-02-01T00:00:00Z')),
        ]));
        $runs = $service->forTemplate(Scenario::template(), Scenario::definition(), '1');
        self::assertCount(1, $runs);
        self::assertSame('completed', $runs[0]->status);
        self::assertSame([KpiMeasurementReason::CrossedVersionBoundary], $runs[0]->e2eDuration->reasons);
        self::assertSame([], $service->forTemplate(Scenario::template(), Scenario::definition(), 'latest'));
        self::assertSame([], $service->forTemplate(Scenario::template(), Scenario::definition(), 'missing'));
    }

    public function testApplicationExposesCoverageByStepAndVisitWithoutReanalysingEvents(): void
    {
        $events = [];
        foreach (['start:before', 'A:after', 'A:before', 'A:after', 'A:before', 'B:unknown', 'end:after'] as $i => $phase) {
            [$step, $phase] = explode(':', $phase);
            $events[] = Scenario::event('coverage-'.$i, $step, $phase, sprintf('2026-02-01T10:%02d:00Z', $i));
        }
        // Eligibility must not erase evidence about observed measurement points.
        $service = new ProcessKpiMeasurements(new InMemoryProcessEventReader($events), new InMemoryProcessVersionRepository());
        $run = $service->forTemplate(Scenario::template(), Scenario::definition())[0];
        $coverageByStep = [];
        foreach ($run->stepVisits as $visit) {
            $coverageByStep[$visit->stepKey][] = $visit->measurementPointCoverage;
            self::assertFalse($visit->duration->isMeasurable());
        }
        self::assertSame([
            'start' => [['before' => true, 'after' => false]],
            'A' => [
                ['before' => false, 'after' => true],
                ['before' => true, 'after' => true],
                ['before' => true, 'after' => false],
            ],
            'B' => [['before' => false, 'after' => false]],
            'end' => [['before' => false, 'after' => true]],
        ], $coverageByStep);
        self::assertArrayNotHasKey('optional', $coverageByStep);
    }

    public function testEmptyHistoryReturnsNoInventedRun(): void
    {
        $service = new ProcessKpiMeasurements(new InMemoryProcessEventReader([]), new InMemoryProcessVersionRepository());
        self::assertSame([], $service->forTemplate(Scenario::template(), Scenario::definition()));
    }
}
