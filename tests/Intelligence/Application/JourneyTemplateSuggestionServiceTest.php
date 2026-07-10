<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\JourneyTemplateSuggestionService;
use App\Intelligence\Domain\ProcessEventRecord;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateMatch;
use App\Intelligence\Domain\ProcessTemplateStep;
use App\Intelligence\Domain\ProcessTemplateTransition;
use App\Intelligence\Infrastructure\Process\InMemoryDocumentTimelineProvider;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class JourneyTemplateSuggestionServiceTest extends TestCase
{
    public function testSuggestsJourneyStepsAndTransitionsFromDocumentTimeline(): void
    {
        $result = $this->service([
            $this->event(1, 'generic_document_import', '2026-06-01T09:00:00+00:00'),
            $this->event(2, 'aufmass_pruefung', '2026-06-01T10:00:00+00:00'),
            $this->event(3, 'export_nevaris', '2026-06-01T11:00:00+00:00'),
        ])->suggest('uuid-1', 'aufmass_journey');

        self::assertNotNull($result);
        self::assertSame('journey', $result->template->scope);
        self::assertSame(
            ['generic_document_import', 'aufmass_pruefung', 'export_nevaris'],
            array_map(static fn (ProcessTemplateStep $step): string => $step->key, $result->template->steps)
        );
        self::assertSame('process', $result->template->steps[0]->type);
        self::assertSame('generic_document_import', $result->template->steps[0]->processKey);
        self::assertTrue($result->template->steps[0]->required);
        self::assertSame(
            [
                ['generic_document_import', 'aufmass_pruefung'],
                ['aufmass_pruefung', 'export_nevaris'],
            ],
            array_map(static fn (ProcessTemplateTransition $transition): array => [$transition->from, $transition->to], $result->template->transitions)
        );
    }

    public function testSingleProcessTimelineHasNoTransitions(): void
    {
        $result = $this->service([
            $this->event(1, 'generic_document_import', '2026-06-01T09:00:00+00:00'),
            $this->event(2, 'generic_document_import', '2026-06-01T09:05:00+00:00'),
        ])->suggest('uuid-1', 'import_journey');

        self::assertNotNull($result);
        self::assertSame(['generic_document_import'], array_map(static fn (ProcessTemplateStep $step): string => $step->key, $result->template->steps));
        self::assertSame([], $result->template->transitions);
    }

    public function testRepeatedProcessUsesStableUniqueStepKeys(): void
    {
        $result = $this->service([
            $this->event(1, 'import', '2026-06-01T09:00:00+00:00'),
            $this->event(2, 'check', '2026-06-01T10:00:00+00:00'),
            $this->event(3, 'import', '2026-06-01T11:00:00+00:00'),
        ])->suggest('uuid-1', 'repeat_journey');

        self::assertNotNull($result);
        self::assertSame(['import', 'check', 'import_2'], array_map(static fn (ProcessTemplateStep $step): string => $step->key, $result->template->steps));
        self::assertSame('import', $result->template->steps[2]->processKey);
        self::assertSame(
            [['import', 'check'], ['check', 'import_2']],
            array_map(static fn (ProcessTemplateTransition $transition): array => [$transition->from, $transition->to], $result->template->transitions)
        );
    }

    public function testExistingJourneyTemplateIsExtendedWithoutDuplicatingKnownStepsAndTransitions(): void
    {
        $existing = new ProcessTemplate(
            'aufmass_journey',
            scope: 'journey',
            match: new ProcessTemplateMatch(['aufmass_pruefung']),
            steps: [
                new ProcessTemplateStep('import', type: 'process', processKey: 'generic_document_import'),
                new ProcessTemplateStep('pruefung', type: 'process', processKey: 'aufmass_pruefung'),
            ],
            transitions: [
                new ProcessTemplateTransition('import', 'pruefung'),
            ]
        );

        $result = $this->service([
            $this->event(1, 'generic_document_import', '2026-06-01T09:00:00+00:00'),
            $this->event(2, 'aufmass_pruefung', '2026-06-01T10:00:00+00:00'),
            $this->event(3, 'export_nevaris', '2026-06-01T11:00:00+00:00'),
        ])->suggest('uuid-1', 'aufmass_journey', $existing);

        self::assertNotNull($result);
        self::assertSame(['import', 'pruefung', 'export_nevaris'], array_map(static fn (ProcessTemplateStep $step): string => $step->key, $result->template->steps));
        self::assertSame(['aufmass_pruefung'], $result->template->match?->anyProcessKeys);
        self::assertSame(
            [['import', 'pruefung'], ['pruefung', 'export_nevaris']],
            array_map(static fn (ProcessTemplateTransition $transition): array => [$transition->from, $transition->to], $result->template->transitions)
        );
        self::assertSame(['journey_step_suggested', 'journey_transition_suggested'], array_map(static fn ($suggestion): string => $suggestion->type, $result->suggestions));
    }

    public function testObservedOrderDifferenceCreatesWarning(): void
    {
        $existing = new ProcessTemplate(
            'aufmass_journey',
            scope: 'journey',
            steps: [
                new ProcessTemplateStep('import', type: 'process', processKey: 'generic_document_import'),
                new ProcessTemplateStep('pruefung', type: 'process', processKey: 'aufmass_pruefung'),
            ],
            transitions: [
                new ProcessTemplateTransition('pruefung', 'import'),
            ]
        );

        $result = $this->service([
            $this->event(1, 'generic_document_import', '2026-06-01T09:00:00+00:00'),
            $this->event(2, 'aufmass_pruefung', '2026-06-01T10:00:00+00:00'),
        ])->suggest('uuid-1', 'aufmass_journey', $existing);

        self::assertNotNull($result);
        self::assertSame('observed_journey_order_differs', $result->warnings[0]->type);
    }

    public function testMissingTimelineReturnsNull(): void
    {
        self::assertNull($this->service([])->suggest('uuid-1', 'empty_journey'));
    }

    public function testEventsWithoutProcessKeyProduceWarningAndNoSteps(): void
    {
        $result = $this->service([
            $this->event(1, '', '2026-06-01T09:00:00+00:00'),
        ])->suggest('uuid-1', 'broken_journey');

        self::assertNotNull($result);
        self::assertSame([], $result->template->steps);
        self::assertSame('missing_process_key', $result->warnings[0]->type);
    }

    /**
     * @param array<int, ProcessEventRecord> $events
     */
    private function service(array $events): JourneyTemplateSuggestionService
    {
        return new JourneyTemplateSuggestionService(new InMemoryDocumentTimelineProvider([], $events));
    }

    private function event(int $id, string $processKey, string $occurredAt): ProcessEventRecord
    {
        $time = new DateTimeImmutable($occurredAt);

        return new ProcessEventRecord(
            $id,
            sprintf('evt-%d', $id),
            'test',
            $processKey,
            'start',
            'start',
            'doc-1',
            'uuid-1',
            1,
            'user-1',
            $time,
            $time,
            '{}',
            '{}',
            1,
            'after'
        );
    }
}
