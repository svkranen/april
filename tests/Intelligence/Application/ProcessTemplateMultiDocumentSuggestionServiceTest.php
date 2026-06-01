<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\ProcessTemplateMultiDocumentSuggestionService;
use App\Intelligence\Domain\ProcessEventRecord;
use App\Intelligence\Infrastructure\Process\InMemoryDocumentTimelineProvider;
use App\Intelligence\Infrastructure\Process\InMemoryProcessDocumentUuidProvider;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class ProcessTemplateMultiDocumentSuggestionServiceTest extends TestCase
{
    public function testSuggestReturnsProcessTemplateSuggestionResult(): void
    {
        $events = [
            $this->event(1, 'doc-a-1', 'eingang', 'doc-a', '2026-05-29T09:00:00+00:00'),
            $this->event(2, 'doc-a-2', 'pruefung', 'doc-a', '2026-05-29T10:00:00+00:00'),
            $this->event(3, 'doc-b-1', 'eingang', 'doc-b', '2026-05-29T09:00:00+00:00'),
            $this->event(4, 'doc-b-2', 'freigabe', 'doc-b', '2026-05-29T10:00:00+00:00'),
        ];
        $service = new ProcessTemplateMultiDocumentSuggestionService(
            new InMemoryDocumentTimelineProvider([], $events),
            new InMemoryProcessDocumentUuidProvider($events)
        );

        $result = $service->suggest(['doc-a', 'doc-b'], 'eingangsrechnung');

        self::assertNotNull($result);
        self::assertSame('eingangsrechnung', $result->template->key);
        self::assertSame(['eingang', 'pruefung', 'freigabe'], array_map(static fn ($step): string => $step->key, $result->template->steps));
        self::assertSame(['doc-a', 'doc-b'], $result->usedDocumentUuids);
        self::assertSame('eingang', $result->transitionSuggestions[0]->from);
        self::assertSame('pruefung', $result->transitionSuggestions[0]->to);
        self::assertSame(1, $result->transitionSuggestions[0]->observedCount);
        self::assertSame(1.0, $result->transitionSuggestions[0]->confidence);
    }

    public function testSuggestsRepeatedEventAnalysisHintWithoutCreatingSignCheck(): void
    {
        $events = [
            $this->event(1, 'doc-a-1', 'A', 'doc-a', '2026-05-29T09:00:00+00:00'),
            $this->event(2, 'doc-a-2', 'B', 'doc-a', '2026-05-29T09:01:00+00:00'),
            $this->event(3, 'doc-a-3', 'B', 'doc-a', '2026-05-29T09:02:00+00:00'),
            $this->event(4, 'doc-a-4', 'B', 'doc-a', '2026-05-29T09:03:00+00:00'),
            $this->event(5, 'doc-a-5', 'C', 'doc-a', '2026-05-29T09:04:00+00:00'),
            $this->event(6, 'doc-b-1', 'A', 'doc-b', '2026-05-29T09:00:00+00:00'),
            $this->event(7, 'doc-b-2', 'B', 'doc-b', '2026-05-29T09:01:00+00:00'),
            $this->event(8, 'doc-b-3', 'B', 'doc-b', '2026-05-29T09:02:00+00:00'),
            $this->event(9, 'doc-b-4', 'C', 'doc-b', '2026-05-29T09:03:00+00:00'),
        ];
        $service = new ProcessTemplateMultiDocumentSuggestionService(
            new InMemoryDocumentTimelineProvider([], $events),
            new InMemoryProcessDocumentUuidProvider($events)
        );

        $result = $service->suggest(['doc-a', 'doc-b'], 'eingangsrechnung');

        self::assertNotNull($result);
        self::assertSame(['A', 'B', 'C'], array_map(static fn ($step): string => $step->key, $result->template->steps));
        self::assertSame([], $result->template->signChecks);

        $hint = $result->suggestions[0] ?? null;
        self::assertNotNull($hint);
        self::assertSame('possible_multi_approval', $hint->type);
        self::assertSame('B', $hint->eventKey);
        self::assertSame(2, $hint->affectedDocuments);
        self::assertSame(2, $hint->minRepetitions);
        self::assertSame(3, $hint->maxRepetitions);
        self::assertSame(2.5, $hint->avgRepetitions);
        self::assertSame([['event_key' => 'A', 'count' => 2]], $hint->previousEvents);
        self::assertSame([['event_key' => 'C', 'count' => 2]], $hint->followingEvents);
    }

    private function event(int $id, string $externalEventKey, string $stepKey, string $documentUuid, string $occurredAt): ProcessEventRecord
    {
        $time = new DateTimeImmutable($occurredAt);

        return new ProcessEventRecord(
            $id,
            $externalEventKey,
            'test',
            'eingangsrechnung',
            $stepKey,
            $stepKey,
            'external-'.$documentUuid,
            $documentUuid,
            1,
            'user-1',
            $time,
            $time,
            '{}',
            '{}',
            1
        );
    }
}
