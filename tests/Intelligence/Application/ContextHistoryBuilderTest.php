<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\ContextHistoryBuilder;
use App\Intelligence\Domain\ContextSnapshot;
use App\Intelligence\Domain\DocumentRef;
use App\Intelligence\Domain\ProcessEventRecord;
use App\Intelligence\Infrastructure\Process\InMemoryContextSnapshotHistoryProvider;
use App\Intelligence\Infrastructure\Process\InMemoryDocumentTimelineProvider;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ContextHistoryBuilderTest extends TestCase
{
    public function testSortsSnapshotsChronologicallyByOccurredAtWithFallbacks(): void
    {
        $builder = new ContextHistoryBuilder(
            new InMemoryContextSnapshotHistoryProvider([
                $this->snapshot('evt-2', ['status' => 'second'], capturedAt: '2026-06-01T09:10:00+00:00', occurredAt: '2026-06-01T09:05:00+00:00'),
                $this->snapshot('evt-1', ['status' => 'first'], capturedAt: '2026-06-01T09:00:00+00:00'),
                $this->snapshot('evt-3', ['status' => 'third'], capturedAt: '2026-06-01T09:20:00+00:00', occurredAt: '2026-06-01T09:15:00+00:00'),
            ]),
            new InMemoryDocumentTimelineProvider([], [
                $this->event('evt-1', 'ready'),
                $this->event('evt-2', 'picked_up'),
                $this->event('evt-3', 'written'),
            ])
        );

        $report = $builder->build('uuid-1', 'invoice');

        self::assertSame(['evt-1', 'evt-2', 'evt-3'], array_map(static fn ($entry): ?string => $entry->externalEventKey, $report->entries));
        self::assertSame('picked_up', $report->entries[1]->stepKey);
    }

    public function testFiltersFieldsAndEmptyValues(): void
    {
        $builder = new ContextHistoryBuilder(
            new InMemoryContextSnapshotHistoryProvider([
                $this->snapshot('evt-1', ['amount_net' => 400.0, 'empty' => '', 'null_value' => null, 'ignored' => 'x']),
            ]),
            new InMemoryDocumentTimelineProvider()
        );

        $withoutEmpty = $builder->build('uuid-1', 'invoice', ['amount_net', 'empty', 'null_value'], false);
        $withEmpty = $builder->build('uuid-1', 'invoice', ['amount_net', 'empty', 'null_value'], true);

        self::assertSame(['amount_net' => 400.0], $withoutEmpty->entries[0]->contextJson);
        self::assertSame(['amount_net' => 400.0, 'empty' => '', 'null_value' => null], $withEmpty->entries[0]->contextJson);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function snapshot(string $externalEventKey, array $attributes, string $capturedAt = '2026-06-01T09:00:00+00:00', ?string $occurredAt = null): ContextSnapshot
    {
        return new ContextSnapshot(
            new DocumentRef('test', 'doc-1', 'uuid-1', 1),
            new DateTimeImmutable($capturedAt),
            $attributes,
            [],
            'invoice',
            $externalEventKey,
            7,
            $occurredAt === null ? null : new DateTimeImmutable($occurredAt)
        );
    }

    private function event(string $externalEventKey, string $stepKey): ProcessEventRecord
    {
        $time = new DateTimeImmutable('2026-06-01T09:00:00+00:00');

        return new ProcessEventRecord(null, $externalEventKey, 'test', 'invoice', $stepKey, $stepKey, 'doc-1', 'uuid-1', 1, null, $time, $time, '{}', '{}');
    }
}
