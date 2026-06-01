<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ContextSnapshot;

final readonly class ContextHistoryBuilder
{
    public function __construct(
        private ContextSnapshotHistoryProvider $snapshotHistoryProvider,
        private DocumentTimelineProvider $timelineProvider
    ) {
    }

    /**
     * @param array<int, string>|null $fields
     */
    public function build(string $documentUuid, string $processKey, ?array $fields = null, bool $withEmpty = false): ContextHistoryReport
    {
        $eventsByExternalKey = [];
        foreach ($this->timelineProvider->build($documentUuid)->events as $event) {
            if ($event->processKey === $processKey) {
                $eventsByExternalKey[$event->externalEventKey] = $event;
            }
        }

        $snapshots = $this->snapshotHistoryProvider->snapshotsForDocument($documentUuid, $processKey);
        usort($snapshots, static fn (ContextSnapshot $left, ContextSnapshot $right): int => self::sortTime($left) <=> self::sortTime($right));

        $entries = [];
        foreach ($snapshots as $snapshot) {
            $event = $snapshot->externalEventKey === null ? null : ($eventsByExternalKey[$snapshot->externalEventKey] ?? null);
            $entries[] = new ContextHistoryEntry(
                self::sortTime($snapshot),
                $snapshot->externalEventKey,
                $event?->eventKey,
                $event?->stepKey,
                $snapshot->document->version,
                $snapshot->processInstanceId,
                $this->filteredContext($snapshot->attributes, $fields, $withEmpty),
                $snapshot->warnings
            );
        }

        return new ContextHistoryReport($documentUuid, $processKey, $entries);
    }

    private static function sortTime(ContextSnapshot $snapshot): \DateTimeImmutable
    {
        return $snapshot->occurredAt ?? $snapshot->capturedAt ?? $snapshot->loadedAt;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<int, string>|null $fields
     * @return array<string, mixed>
     */
    private function filteredContext(array $context, ?array $fields, bool $withEmpty): array
    {
        if ($fields !== null) {
            $wanted = array_fill_keys($fields, true);
            $context = array_filter(
                $context,
                static fn (string $field): bool => isset($wanted[$field]),
                ARRAY_FILTER_USE_KEY
            );
        }

        if ($withEmpty) {
            return $context;
        }

        return array_filter(
            $context,
            static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []
        );
    }
}
