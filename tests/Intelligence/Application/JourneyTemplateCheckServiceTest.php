<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\JourneyTemplateCheckService;
use App\Intelligence\Application\ProcessTemplateProvider;
use App\Intelligence\Domain\ContextSnapshot;
use App\Intelligence\Domain\DocumentRef;
use App\Intelligence\Domain\ProcessEventRecord;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateStep;
use App\Intelligence\Domain\ProcessTemplateTransition;
use App\Intelligence\Infrastructure\Process\InMemoryContextSnapshotHistoryProvider;
use App\Intelligence\Infrastructure\Process\InMemoryDocumentTimelineProvider;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class JourneyTemplateCheckServiceTest extends TestCase
{
    public function testProcessExistsWhenProcessEventsSameVersionArePresent(): void
    {
        $service = $this->service([
            $this->event('import-start', 'generic_document_import', 1, '2026-06-01T09:00:00+00:00'),
            $this->event('pruefung-start', 'aufmass_pruefung', 1, '2026-06-01T10:00:00+00:00'),
        ], [
            $this->snapshot('import-start', 'generic_document_import', 1, ['amagno_known' => 'false']),
        ], ['generic_document_import']);

        $result = $service->check('uuid-1', $this->template());

        self::assertSame(JourneyTemplateCheckService::STATUS_SATISFIED, $result->status);
        self::assertSame(JourneyTemplateCheckService::STEP_PROCESS_EXISTS, $result->stepResults[0]->status);
        self::assertTrue($result->stepResults[0]->knownDetailTemplate);
        self::assertFalse($result->stepResults[1]->knownDetailTemplate);
        self::assertEquals(new DateTimeImmutable('2026-06-01T09:00:00+00:00'), $result->stepResults[0]->startedAt);
        self::assertSame(JourneyTemplateCheckService::TRANSITION_SATISFIED, $result->transitionResults[0]->status);
    }

    public function testMissingRequiredProcessIsDeviation(): void
    {
        $service = $this->service([
            $this->event('import-start', 'generic_document_import', 1, '2026-06-01T09:00:00+00:00'),
        ], [
            $this->snapshot('import-start', 'generic_document_import', 1, ['amagno_known' => false]),
        ]);

        $result = $service->check('uuid-1', $this->template());

        self::assertSame(JourneyTemplateCheckService::STATUS_DEVIATION, $result->status);
        self::assertSame(JourneyTemplateCheckService::STEP_MISSING_REQUIRED_PROCESS, $result->stepResults[1]->status);
    }

    public function testConditionNotApplicableWhenWhenDoesNotMatch(): void
    {
        $service = $this->service([
            $this->event('import-start', 'generic_document_import', 1, '2026-06-01T09:00:00+00:00'),
            $this->event('pruefung-start', 'aufmass_pruefung', 1, '2026-06-01T10:00:00+00:00'),
        ], [
            $this->snapshot('import-start', 'generic_document_import', 1, ['amagno_known' => true]),
        ]);

        $result = $service->check('uuid-1', $this->template());

        self::assertSame(JourneyTemplateCheckService::STATUS_SATISFIED, $result->status);
        self::assertSame(JourneyTemplateCheckService::STEP_CONDITION_NOT_APPLICABLE, $result->stepResults[0]->status);
        self::assertSame(JourneyTemplateCheckService::TRANSITION_NOT_APPLICABLE, $result->transitionResults[0]->status);
    }

    public function testTransitionWrongOrderIsDeviation(): void
    {
        $service = $this->service([
            $this->event('pruefung-start', 'aufmass_pruefung', 1, '2026-06-01T10:00:00+00:00'),
            $this->event('export-start', 'nevaris_export', 1, '2026-06-01T09:30:00+00:00'),
        ]);

        $result = $service->check('uuid-1', new ProcessTemplate(
            'aufmass_verarbeitung',
            scope: 'journey',
            steps: [
                new ProcessTemplateStep('pruefung', type: 'process', processKey: 'aufmass_pruefung'),
                new ProcessTemplateStep('export', type: 'process', processKey: 'nevaris_export'),
            ],
            transitions: [
                new ProcessTemplateTransition('pruefung', 'export'),
            ]
        ));

        self::assertSame(JourneyTemplateCheckService::STATUS_DEVIATION, $result->status);
        self::assertSame(JourneyTemplateCheckService::TRANSITION_WRONG_ORDER, $result->transitionResults[0]->status);
    }

    public function testWarningWhenDocumentVersionOmittedAndMultipleVersionsExist(): void
    {
        $service = $this->service([
            $this->event('import-v1', 'generic_document_import', 1, '2026-06-01T09:00:00+00:00'),
            $this->event('import-v2', 'generic_document_import', 2, '2026-06-01T10:00:00+00:00'),
        ]);

        $result = $service->check('uuid-1', $this->template());

        self::assertSame(JourneyTemplateCheckService::STATUS_WARNING, $result->status);
        self::assertSame(JourneyTemplateCheckService::STEP_WARNING, $result->stepResults[0]->status);
        self::assertNull($result->stepResults[0]->documentVersion);
    }

    public function testWarningWhenProcessExistsOnlyInAnotherVersion(): void
    {
        $service = $this->service([
            $this->event('import-v2', 'generic_document_import', 2, '2026-06-01T09:00:00+00:00'),
        ], [
            $this->snapshot('import-v2', 'generic_document_import', 2, ['amagno_known' => false]),
        ]);

        $result = $service->check('uuid-1', new ProcessTemplate(
            'aufmass_verarbeitung',
            scope: 'journey',
            steps: [
                new ProcessTemplateStep('import', type: 'process', processKey: 'generic_document_import'),
            ]
        ), 1);

        self::assertSame(JourneyTemplateCheckService::STATUS_WARNING, $result->status);
        self::assertSame(JourneyTemplateCheckService::STEP_WARNING, $result->stepResults[0]->status);
        self::assertSame('Process exists only for another document version.', $result->stepResults[0]->messages[0]);
    }

    /**
     * @param array<int, ProcessEventRecord> $events
     * @param array<int, ContextSnapshot> $snapshots
     * @param array<int, string> $knownTemplates
     */
    private function service(array $events, array $snapshots = [], array $knownTemplates = []): JourneyTemplateCheckService
    {
        return new JourneyTemplateCheckService(
            new InMemoryDocumentTimelineProvider([], $events, $snapshots),
            new InMemoryContextSnapshotHistoryProvider($snapshots),
            new class($knownTemplates) implements ProcessTemplateProvider {
                /** @param array<int, string> $knownTemplates */
                public function __construct(private readonly array $knownTemplates)
                {
                }

                public function findByProcessKey(string $processKey): ?ProcessTemplate
                {
                    return in_array($processKey, $this->knownTemplates, true)
                        ? new ProcessTemplate($processKey)
                        : null;
                }
            }
        );
    }

    private function template(): ProcessTemplate
    {
        return new ProcessTemplate(
            'aufmass_verarbeitung',
            scope: 'journey',
            steps: [
                new ProcessTemplateStep(
                    'import',
                    type: 'process',
                    processKey: 'generic_document_import',
                    when: ['amagno_known' => false]
                ),
                new ProcessTemplateStep('pruefung', type: 'process', processKey: 'aufmass_pruefung'),
            ],
            transitions: [
                new ProcessTemplateTransition('import', 'pruefung'),
            ]
        );
    }

    private function event(string $externalEventKey, string $processKey, int $documentVersion, string $occurredAt): ProcessEventRecord
    {
        $time = new DateTimeImmutable($occurredAt);

        return new ProcessEventRecord(
            null,
            $externalEventKey,
            'amagno',
            $processKey,
            'start',
            'start',
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
        $time = new DateTimeImmutable('2026-06-01T09:00:00+00:00');

        return new ContextSnapshot(
            new DocumentRef('amagno', 'doc-1', 'uuid-1', $documentVersion),
            $time,
            $attributes,
            [],
            $processKey,
            $externalEventKey,
            null,
            $time
        );
    }
}
