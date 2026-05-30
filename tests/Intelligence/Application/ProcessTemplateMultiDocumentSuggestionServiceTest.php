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
