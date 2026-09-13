<?php

namespace App\Tests\Intelligence\Domain;

use App\Intelligence\Domain\KpiMeasurementReason as Reason;
use App\Intelligence\Domain\StepVisitReconstructor;
use App\Tests\Intelligence\Domain\Fixtures\KpiScenario as Scenario;
use PHPUnit\Framework\TestCase;

final class StepVisitReconstructorTest extends TestCase
{
    public function testRepeatedStepVisitsRemainSeparate(): void
    {
        $visits = $this->visits(['A:before', 'A:after', 'B:before', 'B:after', 'A:before', 'A:after']);
        $a = array_values(array_filter($visits, static fn ($visit): bool => $visit->stepKey === 'A'));
        self::assertCount(2, $a);
        self::assertSame([1, 2], array_column($a, 'visitNumber'));
        self::assertSame(60.0, $a[0]->duration->seconds);
        self::assertSame(60.0, $a[1]->duration->seconds);
    }

    public function testBeforeWithoutAfterIsOpenAndNotZeroSeconds(): void
    {
        $visit = $this->visits(['A:before'])[0];
        self::assertSame('open', $visit->status);
        self::assertSame([Reason::MissingAfter], $visit->duration->reasons);
        self::assertNull($visit->duration->seconds);
    }

    public function testAfterWithoutBeforeIsCompletedButNotMeasurable(): void
    {
        $visit = $this->visits(['A:after'])[0];
        self::assertSame('completed', $visit->status);
        self::assertSame([Reason::MissingBefore], $visit->duration->reasons);
        self::assertNull($visit->duration->seconds);
    }

    public function testInterleavedParallelStepsPairOnlyWithinTheirOwnStep(): void
    {
        $visits = $this->visits(['A:before', 'B:before', 'A:after', 'B:after']);
        self::assertCount(2, $visits);
        self::assertSame(120.0, $visits[0]->duration->seconds);
        self::assertSame(120.0, $visits[1]->duration->seconds);
    }

    public function testMultipleCandidatesAreEvidenceRatherThanGuessedPairs(): void
    {
        foreach ([['A:before', 'A:before', 'A:after', 'A:after'], ['A:before', 'A:after', 'A:after'],
            ['A:before', 'A:before', 'A:after', 'A:before', 'A:after']] as $phases) {
            $visits = $this->visits($phases);
            self::assertCount(1, $visits);
            self::assertNull($visits[0]->visitNumber);
            self::assertSame('ambiguous', $visits[0]->status);
            self::assertContains(Reason::AmbiguousVisit, $visits[0]->duration->reasons);
            self::assertCount(count($phases), $visits[0]->eventKeys);
        }
    }

    public function testAfterFollowedByBeforeNeverUsesAnAlternativeInterval(): void
    {
        $visits = $this->visits(['A:after', 'A:before']);
        self::assertCount(2, $visits);
        self::assertSame([Reason::MissingBefore], $visits[0]->duration->reasons);
        self::assertSame([Reason::MissingAfter], $visits[1]->duration->reasons);
    }

    public function testUniqueEqualTimePairIsMeasurableZeroButMultipleCandidatesAreNot(): void
    {
        $events = [Scenario::event('a', 'A', 'after', '2026-01-01T01:00:00Z'),
            Scenario::event('b', 'A', 'before', '2026-01-01T01:00:00Z')];
        self::assertSame(0.0, (new StepVisitReconstructor())->reconstruct($events)[0]->duration->seconds);
        $events[] = Scenario::event('c', 'A', 'before', '2026-01-01T01:00:00Z');
        self::assertNull((new StepVisitReconstructor())->reconstruct($events)[0]->duration->seconds);
    }

    public function testUnknownPhaseDoesNotBecomeAfter(): void
    {
        $visit = $this->visits(['A:unknown'])[0];
        self::assertContains(Reason::UnknownPhase, $visit->duration->reasons);
        self::assertNull($visit->duration->seconds);
    }

    /** @dataProvider measurementPointCases */
    public function testCoverageDescribesObservedPhasesEvenWithoutMeasurableDuration(array $phases, array $expected): void
    {
        $visit = $this->visits($phases)[0];
        self::assertSame($expected, $visit->measurementPointCoverage);
    }

    public static function measurementPointCases(): iterable
    {
        yield 'both' => [['A:before', 'A:after'], ['before' => true, 'after' => true]];
        yield 'before only' => [['A:before'], ['before' => true, 'after' => false]];
        yield 'after only' => [['A:after'], ['before' => false, 'after' => true]];
        yield 'no usable phases' => [['A:unknown'], ['before' => false, 'after' => false]];
        yield 'both but ambiguous' => [['A:before', 'A:before', 'A:after', 'A:after'], ['before' => true, 'after' => true]];
    }

    public function testAmbiguousCoverageDoesNotInventUniqueTimestampsOrDuration(): void
    {
        $visit = $this->visits(['A:before', 'A:before', 'A:after', 'A:after'])[0];
        self::assertSame(['before' => true, 'after' => true], $visit->measurementPointCoverage);
        self::assertNull($visit->beforeAt);
        self::assertNull($visit->afterAt);
        self::assertNull($visit->duration->seconds);
    }

    public function testRepeatedVisitsRetainTheirDifferentMeasurementPointCoverage(): void
    {
        $visits = $this->visits(['A:after', 'A:before', 'A:after', 'A:before']);
        self::assertSame([1, 2, 3], array_column($visits, 'visitNumber'));
        self::assertSame([
            ['before' => false, 'after' => true],
            ['before' => true, 'after' => true],
            ['before' => true, 'after' => false],
        ], array_column($visits, 'measurementPointCoverage'));
        self::assertNull($visits[0]->duration->seconds);
        self::assertSame(60.0, $visits[1]->duration->seconds);
        self::assertNull($visits[2]->duration->seconds);
    }

    private function visits(array $phases): array
    {
        $events = [];
        foreach ($phases as $i => $phase) {
            [$step, $phase] = explode(':', $phase);
            $events[] = Scenario::event('event-'.$i, $step, $phase, sprintf('2026-01-01T01:%02d:00Z', $i));
        }

        return (new StepVisitReconstructor())->reconstruct($events);
    }
}
