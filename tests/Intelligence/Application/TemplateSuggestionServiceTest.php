<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\JourneyTemplateSuggestionService;
use App\Intelligence\Application\ProcessTemplateProvider;
use App\Intelligence\Application\ProcessTemplateSuggestionService;
use App\Intelligence\Application\TemplateSuggestionService;
use App\Intelligence\Domain\ProcessEventRecord;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Infrastructure\Process\InMemoryDocumentTimelineProvider;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TemplateSuggestionServiceTest extends TestCase
{
    public function testDetectsProcessTemplateAutomatically(): void
    {
        $result = $this->service(
            [
                'invoice' => new ProcessTemplate('invoice', scope: 'process'),
            ],
            [
                $this->event(1, 'invoice', 'received', '2026-06-01T09:00:00+00:00'),
                $this->event(2, 'other', 'ignored', '2026-06-01T09:05:00+00:00'),
            ]
        )->suggestFromDocument('uuid-1', 'invoice');

        self::assertNotNull($result);
        self::assertSame('process', $result->template->scope);
        self::assertSame(['received'], array_map(static fn ($step): string => $step->key, $result->template->steps));
    }

    public function testLegacyTemplateWithoutScopeStaysProcessSuggestion(): void
    {
        $result = $this->service(
            [
                'invoice' => new ProcessTemplate('invoice'),
            ],
            [
                $this->event(1, 'invoice', 'received', '2026-06-01T09:00:00+00:00'),
            ]
        )->suggestFromDocument('uuid-1', 'invoice');

        self::assertNotNull($result);
        self::assertSame('process', $result->template->scope);
        self::assertSame(['received'], array_map(static fn ($step): string => $step->key, $result->template->steps));
    }

    public function testDetectsJourneyTemplateAutomatically(): void
    {
        $result = $this->service(
            [
                'document_journey' => new ProcessTemplate('document_journey', scope: 'journey'),
            ],
            [
                $this->event(1, 'import', 'start', '2026-06-01T09:00:00+00:00'),
                $this->event(2, 'export', 'start', '2026-06-01T10:00:00+00:00'),
            ]
        )->suggestFromDocument('uuid-1', 'document_journey');

        self::assertNotNull($result);
        self::assertSame('journey', $result->template->scope);
        self::assertSame(['import', 'export'], array_map(static fn ($step): string => $step->key, $result->template->steps));
    }

    public function testScopeOverrideCanCreateJourneySuggestionWithoutExistingTemplate(): void
    {
        $result = $this->service(
            [],
            [
                $this->event(1, 'import', 'start', '2026-06-01T09:00:00+00:00'),
            ]
        )->suggestFromDocument('uuid-1', 'new_journey', scopeOverride: 'journey');

        self::assertNotNull($result);
        self::assertSame('journey', $result->template->scope);
        self::assertSame('new_journey', $result->template->key);
    }

    public function testUnknownScopeIsRejectedCentrally(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported template scope "case"');

        $this->service(
            [
                'bad' => new ProcessTemplate('bad', scope: 'case'),
            ],
            [
                $this->event(1, 'bad', 'start', '2026-06-01T09:00:00+00:00'),
            ]
        )->suggestFromDocument('uuid-1', 'bad');
    }

    /**
     * @param array<string, ProcessTemplate> $templates
     * @param array<int, ProcessEventRecord> $events
     */
    private function service(array $templates, array $events): TemplateSuggestionService
    {
        $timelineProvider = new InMemoryDocumentTimelineProvider([], $events);

        return new TemplateSuggestionService(
            new ProcessTemplateSuggestionService($timelineProvider),
            new JourneyTemplateSuggestionService($timelineProvider),
            new class($templates) implements ProcessTemplateProvider {
                /** @param array<string, ProcessTemplate> $templates */
                public function __construct(private readonly array $templates)
                {
                }

                public function findByProcessKey(string $processKey): ?ProcessTemplate
                {
                    return $this->templates[$processKey] ?? null;
                }
            }
        );
    }

    private function event(int $id, string $processKey, string $stepKey, string $occurredAt): ProcessEventRecord
    {
        $time = new DateTimeImmutable($occurredAt);

        return new ProcessEventRecord(
            $id,
            sprintf('evt-%d', $id),
            'test',
            $processKey,
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
