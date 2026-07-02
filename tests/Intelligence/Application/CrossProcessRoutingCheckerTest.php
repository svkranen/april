<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\CrossProcessRoutingChecker;
use App\Intelligence\Application\DocumentTimelineEventRow;
use App\Intelligence\Application\DocumentTimelineProvider;
use App\Intelligence\Application\DocumentTimelineReport;
use App\Intelligence\Application\EventTimelineOrder;
use App\Intelligence\Domain\ContextSnapshot;
use App\Intelligence\Domain\DocumentRef;
use App\Intelligence\Domain\ProcessEventRecord;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateCrossProcessRoutingRule;
use App\Intelligence\Infrastructure\Process\InMemoryContextSnapshotHistoryProvider;
use App\Intelligence\Infrastructure\Process\InMemoryDocumentTimelineProvider;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class CrossProcessRoutingCheckerTest extends TestCase
{
    public function testSatisfiedWhenTargetProcessExistsInSameVersionAfterRouting(): void
    {
        $checker = $this->checker([
            $this->event('source-route', 'debitoren_intake', '10 Intake abgeschlossen', 1, '2026-06-01T10:00:00+00:00'),
            $this->event('target-start', 'aufmass_workflow', 'aufmass_eingang', 1, '2026-06-01T10:05:00+00:00'),
        ], [
            $this->snapshot('source-route', 'debitoren_intake', 1, ['document_type' => 'aufmass']),
        ]);

        $result = $checker->check('uuid-1', 'debitoren_intake', $this->template());

        self::assertSame(CrossProcessRoutingChecker::STATUS_SATISFIED, $result->status);
        self::assertSame(CrossProcessRoutingChecker::STATUS_SATISFIED, $result->ruleResults[0]->status);
        self::assertSame('route_to_aufmass', $result->ruleResults[0]->ruleKey);
        self::assertEquals(new DateTimeImmutable('2026-06-01T10:05:00+00:00'), $result->ruleResults[0]->targetStartedAt);
    }

    public function testDeviationWhenTargetProcessIsMissing(): void
    {
        $checker = $this->checker([
            $this->event('source-route', 'debitoren_intake', '10 Intake abgeschlossen', 1, '2026-06-01T10:00:00+00:00'),
        ], [
            $this->snapshot('source-route', 'debitoren_intake', 1, ['document_type' => 'aufmass']),
        ]);

        $result = $checker->check('uuid-1', 'debitoren_intake', $this->template());

        self::assertSame(CrossProcessRoutingChecker::STATUS_DEVIATION, $result->status);
        self::assertSame('Expected target process is missing for this document.', $result->ruleResults[0]->messages[0]);
    }

    public function testDeviationWhenTargetProcessStartsBeforeRouting(): void
    {
        $checker = $this->checker([
            $this->event('target-start', 'aufmass_workflow', 'aufmass_eingang', 1, '2026-06-01T09:55:00+00:00'),
            $this->event('source-route', 'debitoren_intake', '10 Intake abgeschlossen', 1, '2026-06-01T10:00:00+00:00'),
        ], [
            $this->snapshot('source-route', 'debitoren_intake', 1, ['document_type' => 'aufmass']),
        ]);

        $result = $checker->check('uuid-1', 'debitoren_intake', $this->template());

        self::assertSame(CrossProcessRoutingChecker::STATUS_DEVIATION, $result->status);
        self::assertSame('Expected target process exists, but starts before the routing event.', $result->ruleResults[0]->messages[0]);
    }

    public function testNotApplicableWhenWhenConditionDoesNotMatch(): void
    {
        $checker = $this->checker([
            $this->event('source-route', 'debitoren_intake', '10 Intake abgeschlossen', 1, '2026-06-01T10:00:00+00:00'),
            $this->event('target-start', 'aufmass_workflow', 'aufmass_eingang', 1, '2026-06-01T10:05:00+00:00'),
        ], [
            $this->snapshot('source-route', 'debitoren_intake', 1, ['document_type' => 'ausgangsrechnung']),
        ]);

        $result = $checker->check('uuid-1', 'debitoren_intake', $this->template());

        self::assertSame(CrossProcessRoutingChecker::STATUS_NOT_APPLICABLE, $result->status);
        self::assertSame(CrossProcessRoutingChecker::STATUS_NOT_APPLICABLE, $result->ruleResults[0]->status);
    }

    public function testWarningWhenTargetProcessExistsOnlyInAnotherVersion(): void
    {
        $checker = $this->checker([
            $this->event('source-route', 'debitoren_intake', '10 Intake abgeschlossen', 1, '2026-06-01T10:00:00+00:00'),
            $this->event('target-start', 'aufmass_workflow', 'aufmass_eingang', 2, '2026-06-01T10:05:00+00:00'),
        ], [
            $this->snapshot('source-route', 'debitoren_intake', 1, ['document_type' => 'aufmass']),
        ]);

        $result = $checker->check('uuid-1', 'debitoren_intake', $this->template());

        self::assertSame(CrossProcessRoutingChecker::STATUS_WARNING, $result->status);
        self::assertSame('Expected target process exists only for another document version.', $result->ruleResults[0]->messages[0]);
    }

    public function testWarningWhenDocumentVersionIsOmittedAndSourceTimelineHasMultipleVersions(): void
    {
        $checker = $this->checker([
            $this->event('source-route-v1', 'debitoren_intake', '10 Intake abgeschlossen', 1, '2026-06-01T10:00:00+00:00'),
            $this->event('source-route-v2', 'debitoren_intake', '10 Intake abgeschlossen', 2, '2026-06-01T11:00:00+00:00'),
            $this->event('target-start', 'aufmass_workflow', 'aufmass_eingang', 1, '2026-06-01T10:05:00+00:00'),
        ], [
            $this->snapshot('source-route-v1', 'debitoren_intake', 1, ['document_type' => 'aufmass']),
            $this->snapshot('source-route-v2', 'debitoren_intake', 2, ['document_type' => 'aufmass']),
        ]);

        $result = $checker->check('uuid-1', 'debitoren_intake', $this->template());

        self::assertSame(CrossProcessRoutingChecker::STATUS_WARNING, $result->status);
        self::assertNull($result->ruleResults[0]->documentVersion);
        self::assertSame('Multiple source document versions found. Pass --document-version to avoid ambiguous cross-process routing checks.', $result->ruleResults[0]->messages[0]);
    }

    public function testStructuredSnapshotWinsOverContextSummaryFallback(): void
    {
        $routingTime = new DateTimeImmutable('2026-06-01T10:00:00+00:00');
        $targetTime = new DateTimeImmutable('2026-06-01T10:05:00+00:00');
        $timelineProvider = new StaticTimelineProvider(new DocumentTimelineReport('uuid-1', [], [
            new DocumentTimelineEventRow(
                'source-route',
                '10 Intake abgeschlossen',
                '10 Intake abgeschlossen',
                'debitoren_intake',
                1,
                $routingTime,
                $routingTime,
                null,
                null,
                ['attributes' => ['document_type' => 'ausgangsrechnung']],
                'after'
            ),
            new DocumentTimelineEventRow(
                'target-start',
                'aufmass_eingang',
                'aufmass_eingang',
                'aufmass_workflow',
                1,
                $targetTime,
                $targetTime,
                null,
                null,
                null,
                'after'
            ),
        ]));
        $checker = new CrossProcessRoutingChecker(
            $timelineProvider,
            new InMemoryContextSnapshotHistoryProvider([
                $this->snapshot('source-route', 'debitoren_intake', 1, ['document_type' => 'aufmass']),
            ])
        );

        $result = $checker->check('uuid-1', 'debitoren_intake', $this->template());

        self::assertSame(CrossProcessRoutingChecker::STATUS_SATISFIED, $result->status);
    }

    public function testWhenEqualityIsStableForNumberAndBoolScalars(): void
    {
        $checker = $this->checker([
            $this->event('source-route', 'debitoren_intake', '10 Intake abgeschlossen', 1, '2026-06-01T10:00:00+00:00'),
            $this->event('target-start', 'aufmass_workflow', 'aufmass_eingang', 1, '2026-06-01T10:05:00+00:00'),
        ], [
            $this->snapshot('source-route', 'debitoren_intake', 1, ['amount' => '1000', 'approved' => 'true']),
        ]);
        $template = new ProcessTemplate(
            'debitoren_intake',
            crossProcessRoutingRules: [
                new ProcessTemplateCrossProcessRoutingRule(
                    'route_to_aufmass',
                    '10 Intake abgeschlossen',
                    ['amount' => 1000, 'approved' => true],
                    'aufmass_workflow'
                ),
            ]
        );

        $result = $checker->check('uuid-1', 'debitoren_intake', $template);

        self::assertSame(CrossProcessRoutingChecker::STATUS_SATISFIED, $result->status);
    }

    /**
     * @param array<int, ProcessEventRecord> $events
     * @param array<int, ContextSnapshot> $snapshots
     */
    private function checker(array $events, array $snapshots): CrossProcessRoutingChecker
    {
        return new CrossProcessRoutingChecker(
            new InMemoryDocumentTimelineProvider([], $events, $snapshots),
            new InMemoryContextSnapshotHistoryProvider($snapshots)
        );
    }

    private function template(): ProcessTemplate
    {
        return new ProcessTemplate(
            'debitoren_intake',
            crossProcessRoutingRules: [
                new ProcessTemplateCrossProcessRoutingRule(
                    'route_to_aufmass',
                    '10 Intake abgeschlossen',
                    ['document_type' => 'aufmass'],
                    'aufmass_workflow'
                ),
            ]
        );
    }

    private function event(string $externalEventKey, string $processKey, string $stepKey, int $documentVersion, string $occurredAt): ProcessEventRecord
    {
        $time = new DateTimeImmutable($occurredAt);

        return new ProcessEventRecord(
            null,
            $externalEventKey,
            'amagno',
            $processKey,
            $stepKey,
            $stepKey,
            'doc-1',
            'uuid-1',
            $documentVersion,
            'user-1',
            $time,
            $time,
            '{}',
            '{}',
            null,
            'after'
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function snapshot(string $externalEventKey, string $processKey, int $documentVersion, array $attributes): ContextSnapshot
    {
        return new ContextSnapshot(
            new DocumentRef('amagno', 'doc-1', 'uuid-1', $documentVersion),
            new DateTimeImmutable('2026-06-01T10:00:00+00:00'),
            $attributes,
            [],
            $processKey,
            $externalEventKey,
            null,
            new DateTimeImmutable('2026-06-01T10:00:00+00:00')
        );
    }
}

final readonly class StaticTimelineProvider implements DocumentTimelineProvider
{
    public function __construct(
        private DocumentTimelineReport $report
    ) {
    }

    public function build(string $documentUuid, EventTimelineOrder $order = EventTimelineOrder::DEFAULT): DocumentTimelineReport
    {
        return $this->report;
    }
}
