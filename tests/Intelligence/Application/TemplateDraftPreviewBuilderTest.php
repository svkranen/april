<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\DocumentTimelineEventRow;
use App\Intelligence\Application\DocumentTimelineProvider;
use App\Intelligence\Application\DocumentTimelineReport;
use App\Intelligence\Application\EventTimelineOrder;
use App\Intelligence\Application\JourneyTemplateSuggestionService;
use App\Intelligence\Application\ProcessTemplateGraphFactory;
use App\Intelligence\Application\ProcessTemplateProvider;
use App\Intelligence\Application\ProcessTemplateSuggestionService;
use App\Intelligence\Application\TemplateDraftPreviewBuilder;
use App\Intelligence\Application\TemplateMermaidGraphBuilder;
use App\Intelligence\Application\TemplateSuggestionService;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateTransition;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class TemplateDraftPreviewBuilderTest extends TestCase
{
    public function testProcessDraftIsGeneratedWithMermaidPreview(): void
    {
        $builder = $this->builder([], [
            $this->event('evt-1', 'invoice', 'received', '2026-06-01T09:00:00+00:00'),
            $this->event('evt-2', 'invoice', 'approved', '2026-06-01T10:00:00+00:00'),
        ]);

        $preview = $builder->build('uuid-1', 'invoice', 'process');

        self::assertTrue($preview->found);
        self::assertTrue($preview->isValid());
        self::assertNotNull($preview->yaml);
        self::assertStringContainsString('key: invoice', $preview->yaml);
        self::assertStringContainsString('received', $preview->yaml);
        self::assertNull($preview->validationError);
        self::assertNotNull($preview->mermaidCode);
        self::assertStringContainsString('flowchart TD', $preview->mermaidCode);
        self::assertSame(2, $preview->stepCount);
        self::assertSame(1, $preview->transitionCount);
        self::assertSame('invoice.yaml', $preview->downloadFilename());
    }

    public function testJourneyWarningsArePassedThrough(): void
    {
        $builder = $this->builder([], [
            $this->event('evt-1', '', 'orphaned', '2026-06-01T09:00:00+00:00'),
            $this->event('evt-2', 'import', 'start', '2026-06-01T10:00:00+00:00'),
        ]);

        $preview = $builder->build('uuid-1', 'new-journey', 'journey');

        self::assertTrue($preview->found);
        self::assertSame('journey', $preview->scope);
        self::assertNotSame([], $preview->warnings);
        self::assertSame('missing_process_key', $preview->warnings[0]->type);
        self::assertStringContainsString('scope: journey', (string) $preview->yaml);
        self::assertStringContainsString('key: new-journey', (string) $preview->yaml);
    }

    public function testInconsistentDraftReportsFactoryValidationErrorInsteadOfMermaid(): void
    {
        // Intentionally inconsistent existing journey template: its transition
        // references steps that do not exist. The journey suggestion keeps
        // existing transitions, so the round-trip through the regular template
        // factory must fail and the preview must surface that instead of a graph.
        $builder = $this->builder([
            'broken-journey' => new ProcessTemplate(
                'broken-journey',
                transitions: [new ProcessTemplateTransition('ghost-from', 'ghost-to')],
                scope: 'journey'
            ),
        ], [
            $this->event('evt-1', 'import', 'start', '2026-06-01T09:00:00+00:00'),
        ]);

        $preview = $builder->build('uuid-1', 'broken-journey', 'journey');

        self::assertTrue($preview->found);
        self::assertFalse($preview->isValid());
        self::assertNotNull($preview->yaml);
        self::assertNotNull($preview->validationError);
        self::assertStringContainsString('ghost-from', $preview->validationError);
        self::assertNull($preview->mermaidCode);
    }

    public function testDocumentWithoutMatchingEventsYieldsNotFound(): void
    {
        $builder = $this->builder([], []);

        $preview = $builder->build('unknown-uuid', 'invoice', 'process');

        self::assertFalse($preview->found);
        self::assertNull($preview->yaml);
        self::assertNull($preview->errorMessage);
        self::assertFalse($preview->isValid());
    }

    public function testUnsupportedScopeYieldsBusinessErrorInsteadOfException(): void
    {
        $builder = $this->builder([], [
            $this->event('evt-1', 'invoice', 'received', '2026-06-01T09:00:00+00:00'),
        ]);

        $preview = $builder->build('uuid-1', 'invoice', 'case');

        self::assertFalse($preview->found);
        self::assertNotNull($preview->errorMessage);
        self::assertStringContainsString('Unsupported template scope', $preview->errorMessage);
    }

    /**
     * @param array<string, ProcessTemplate> $templates
     * @param array<int, DocumentTimelineEventRow> $events
     */
    private function builder(array $templates, array $events): TemplateDraftPreviewBuilder
    {
        $timelineProvider = new class($events) implements DocumentTimelineProvider {
            /** @param array<int, DocumentTimelineEventRow> $events */
            public function __construct(private readonly array $events)
            {
            }

            public function build(string $documentUuid, EventTimelineOrder $order = EventTimelineOrder::DEFAULT): DocumentTimelineReport
            {
                return new DocumentTimelineReport($documentUuid, [], $this->events);
            }
        };

        $templateProvider = new class($templates) implements ProcessTemplateProvider {
            /** @param array<string, ProcessTemplate> $templates */
            public function __construct(private readonly array $templates)
            {
            }

            public function findByProcessKey(string $processKey): ?ProcessTemplate
            {
                return $this->templates[$processKey] ?? null;
            }
        };

        return new TemplateDraftPreviewBuilder(
            new TemplateSuggestionService(
                new ProcessTemplateSuggestionService($timelineProvider),
                new JourneyTemplateSuggestionService($timelineProvider),
                $templateProvider
            ),
            new TemplateMermaidGraphBuilder(new ProcessTemplateGraphFactory())
        );
    }

    private function event(string $externalEventKey, string $processKey, string $stepKey, string $occurredAt): DocumentTimelineEventRow
    {
        $time = new DateTimeImmutable($occurredAt);

        return new DocumentTimelineEventRow(
            externalEventKey: $externalEventKey,
            eventKey: $stepKey,
            stepKey: $stepKey,
            processKey: $processKey,
            documentVersion: 1,
            occurredAt: $time,
            receivedAt: $time,
            id: 1,
            processInstanceId: null,
            contextSummary: null,
            eventPhase: 'after'
        );
    }
}
