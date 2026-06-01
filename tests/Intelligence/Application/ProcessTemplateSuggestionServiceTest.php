<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\ProcessTemplateSuggestionService;
use App\Intelligence\Domain\ProcessEventRecord;
use App\Intelligence\Infrastructure\Process\InMemoryDocumentTimelineProvider;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class ProcessTemplateSuggestionServiceTest extends TestCase
{
    public function testSuggestReturnsProcessTemplateSuggestionResult(): void
    {
        $service = new ProcessTemplateSuggestionService(
            new InMemoryDocumentTimelineProvider(
                [],
                [
                    $this->event(1, 'eingang', 0),
                    $this->event(2, 'pruefung', 1),
                ]
            )
        );

        $result = $service->suggest('uuid-1', 'eingangsrechnung', 1);

        self::assertNotNull($result);
        self::assertSame('eingangsrechnung', $result->template->key);
        self::assertSame('Eingangsrechnung', $result->template->name);
        self::assertSame('draft', $result->template->version);
        self::assertSame(['eingang', 'pruefung'], array_map(static fn ($step): string => $step->key, $result->template->steps));
        self::assertSame('eingang', $result->template->transitions[0]->from);
        self::assertSame('pruefung', $result->template->transitions[0]->to);
        self::assertSame([], $result->template->contextProfileRequiredFields);
    }

    public function testSuggestsRepeatedEventAnalysisHintForSingleDocument(): void
    {
        $service = new ProcessTemplateSuggestionService(
            new InMemoryDocumentTimelineProvider(
                [],
                [
                    $this->event(1, 'A', 0),
                    $this->event(2, 'B', 1),
                    $this->event(3, 'B', 2),
                    $this->event(4, 'C', 3),
                ]
            )
        );

        $result = $service->suggest('uuid-1', 'eingangsrechnung', 1);

        self::assertNotNull($result);
        self::assertSame(['A', 'B', 'C'], array_map(static fn ($step): string => $step->key, $result->template->steps));
        self::assertSame([], $result->template->signChecks);
        self::assertSame('possible_multi_approval', $result->suggestions[0]->type);
        self::assertSame('B', $result->suggestions[0]->eventKey);
        self::assertSame(2, $result->suggestions[0]->minRepetitions);
        self::assertSame([['event_key' => 'A', 'count' => 1]], $result->suggestions[0]->previousEvents);
        self::assertSame([['event_key' => 'C', 'count' => 1]], $result->suggestions[0]->followingEvents);
    }

    private function event(int $id, string $stepKey, int $minuteOffset): ProcessEventRecord
    {
        $time = (new DateTimeImmutable('2026-05-29T09:00:00+00:00'))->modify(sprintf('+%d minutes', $minuteOffset));

        return new ProcessEventRecord(
            $id,
            sprintf('evt-%d', $id),
            'test',
            'eingangsrechnung',
            $stepKey,
            $stepKey,
            'doc-1',
            'uuid-1',
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
