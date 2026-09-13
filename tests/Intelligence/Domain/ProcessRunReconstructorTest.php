<?php

namespace App\Tests\Intelligence\Domain;

use App\Intelligence\Domain\KpiMeasurementReason as Reason;
use App\Intelligence\Domain\ProcessRunReconstructor;
use App\Intelligence\Domain\ProcessVersion;
use App\Tests\Intelligence\Domain\Fixtures\KpiScenario as Scenario;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ProcessRunReconstructorTest extends TestCase
{
    public function testLinearProcessAcrossMonthBoundaryUsesCompleteOccurredTimes(): void
    {
        $run = Scenario::runs(Scenario::linear())[0];

        self::assertSame('completed', $run->status);
        self::assertSame(7200.0, $run->e2eDuration->seconds);
        self::assertSame(3600.0, $run->stepVisits[1]->duration->seconds);
        self::assertSame('1', $run->eligibility->processVersion->version);
        self::assertCount(3, $run->stepVisits); // The optional step is not required for completion.
    }

    public function testObservedCompletionWithoutStartDoesNotInventE2e(): void
    {
        $run = Scenario::runs(array_slice(Scenario::linear(), 2))[0];

        self::assertSame('completed', $run->status);
        self::assertNull($run->startedAt);
        self::assertNotNull($run->endedAt);
        self::assertNull($run->e2eDuration->seconds);
        self::assertContains(Reason::MissingStart, $run->e2eDuration->reasons);
        self::assertContains(Reason::StartedMidProcess, $run->e2eDuration->reasons);
    }

    public function testMissingEndLeavesRunRunningButCompletedVisitsMeasurable(): void
    {
        $run = Scenario::runs(array_slice(Scenario::linear(), 0, -1))[0];

        self::assertSame('running', $run->status);
        self::assertNull($run->endedAt);
        self::assertSame([Reason::MissingEnd], $run->e2eDuration->reasons);
        self::assertSame(3600.0, $run->stepVisits[1]->duration->seconds);
    }

    public function testLateStartCorrectsPreviouslyIncompleteHistoryWithoutReceiptTimeFallback(): void
    {
        $events = array_slice(Scenario::linear(), 1);
        self::assertFalse(Scenario::runs($events)[0]->e2eDuration->isMeasurable());
        $events[] = Scenario::event('s', 'start', 'before', '2026-01-31T23:00:00Z', '2026-03-01T12:00:00Z');

        self::assertSame(7200.0, Scenario::runs(array_reverse($events))[0]->e2eDuration->seconds);
    }

    public function testTechnicalDuplicateKeysDoNotCreateVisits(): void
    {
        $events = Scenario::linear();
        $run = Scenario::runs([...$events, ...$events])[0];

        self::assertCount(5, $run->eventKeys);
        self::assertCount(3, $run->stepVisits);
        self::assertSame(7200.0, $run->e2eDuration->seconds);
    }

    public function testCompletedThenStartedItemCreatesSeparateRuns(): void
    {
        $events = Scenario::linear();
        $events[] = Scenario::event('s2', 'start', 'before', '2026-02-02T10:00:00Z');
        $events[] = Scenario::event('e2', 'end', 'after', '2026-02-02T11:00:00Z');
        $runs = Scenario::runs($events);

        self::assertCount(2, $runs);
        self::assertNotSame($runs[0]->key, $runs[1]->key);
        self::assertSame(3600.0, $runs[1]->e2eDuration->seconds);
    }

    public function testRepeatedStartWithoutCompletionIsAmbiguousRatherThanInventingRuns(): void
    {
        $events = Scenario::linear();
        $events[] = Scenario::event('s2', 'start', 'before', '2026-01-31T23:10:00Z');
        $runs = Scenario::runs($events);

        self::assertCount(1, $runs);
        self::assertNull($runs[0]->startedAt);
        self::assertContains(Reason::AmbiguousRun, $runs[0]->e2eDuration->reasons);
    }

    public function testDocumentVersionChangeIsNotAssumedToBeTheSameBusinessRun(): void
    {
        $events = Scenario::linear();
        $events[4] = Scenario::event('e', 'end', 'after', '2026-02-01T01:00:00Z', version: 2);
        $run = Scenario::runs($events)[0];

        self::assertCount(2, $run->documents);
        self::assertSame('completed', $run->status);
        self::assertContains(Reason::AmbiguousRun, $run->e2eDuration->reasons);
    }

    public function testSeparateItemsWithoutUuidsAreNotMerged(): void
    {
        $events = Scenario::linear();
        $events[] = Scenario::event('other-end', 'end', 'after', '2026-02-01T01:00:00Z', item: 'item-2');
        self::assertCount(2, Scenario::runs($events));
    }

    public function testVersionBoundaryDisablesDurationsButPreservesCompletion(): void
    {
        $versions = [...Scenario::versions(), new ProcessVersion(null, 'review', '2', new DateTimeImmutable('2026-02-01T00:00:00Z'))];
        $run = (new ProcessRunReconstructor())->reconstruct(Scenario::template(), Scenario::definition(), Scenario::linear(), $versions)[0];

        self::assertSame('completed', $run->status);
        self::assertSame([Reason::CrossedVersionBoundary], $run->e2eDuration->reasons);
        self::assertContains(Reason::CrossedVersionBoundary, $run->stepVisits[1]->duration->reasons);
        self::assertNull($run->stepVisits[1]->duration->seconds);
    }

    public function testNoBaselineAndBeforeBaselineRemainExplicitlyIneligible(): void
    {
        $resolver = new ProcessRunReconstructor();
        $run = $resolver->reconstruct(Scenario::template(), Scenario::definition(), Scenario::linear(), [])[0];
        self::assertContains(Reason::NoProcessVersion, $run->e2eDuration->reasons);
        $run = $resolver->reconstruct(Scenario::template(), Scenario::definition(), Scenario::linear(), [
            new ProcessVersion(null, 'review', 'future', new DateTimeImmutable('2027-01-01T00:00:00Z')),
        ])[0];
        self::assertContains(Reason::BeforeBaseline, $run->e2eDuration->reasons);
    }

    public function testEventsAfterCompletionWithoutNewStartRemainAmbiguous(): void
    {
        $events = [...Scenario::linear(), Scenario::event('late-step', 'B', 'after', '2026-02-02T01:00:00Z')];
        $run = Scenario::runs($events)[0];
        self::assertContains(Reason::AmbiguousRun, $run->e2eDuration->reasons);
        self::assertSame('completed', $run->status);
    }

    public function testEqualTimeNewStartAndVisitAreAssignedTogetherRegardlessOfKeys(): void
    {
        $events = [...Scenario::linear(),
            Scenario::event('z-start', 'start', 'before', '2026-02-02T10:00:00Z'),
            Scenario::event('a-visit', 'A', 'before', '2026-02-02T10:00:00Z'),
            Scenario::event('finish', 'end', 'after', '2026-02-02T11:00:00Z')];
        $runs = Scenario::runs($events);
        self::assertCount(2, $runs);
        self::assertCount(5, $runs[0]->eventKeys);
        self::assertContains('a-visit', $runs[1]->eventKeys);
        self::assertSame(3600.0, $runs[1]->e2eDuration->seconds);
    }
}
