<?php

namespace App\Intelligence\Infrastructure\Process;

use App\Intelligence\Application\EventListFilter;
use App\Intelligence\Application\EventListProvider;
use App\Intelligence\Application\EventListRow;
use App\Intelligence\Domain\ProcessEventRecord;

final class InMemoryEventListProvider implements EventListProvider
{
    /**
     * @param array<int, ProcessEventRecord> $events
     */
    public function __construct(
        private readonly array $events = []
    ) {
    }

    public function list(EventListFilter $filter): array
    {
        $events = array_values(array_filter(
            $this->events,
            static fn (ProcessEventRecord $event): bool => self::matches($event, $filter)
        ));

        usort($events, static fn (ProcessEventRecord $left, ProcessEventRecord $right): int => [
            $right->receivedAt,
            $right->id ?? 0,
        ] <=> [
            $left->receivedAt,
            $left->id ?? 0,
        ]);

        return array_map(
            static fn (ProcessEventRecord $event): EventListRow => new EventListRow(
                $event->id,
                $event->externalEventKey,
                $event->processKey,
                $event->eventKey,
                $event->stepKey,
                $event->documentExternalId,
                $event->documentUuid,
                $event->documentVersion,
                $event->processInstanceId,
                $event->occurredAt,
                $event->receivedAt
            ),
            array_slice($events, 0, $filter->limit)
        );
    }

    private static function matches(ProcessEventRecord $event, EventListFilter $filter): bool
    {
        if ($filter->processKey !== null && $event->processKey !== $filter->processKey) {
            return false;
        }

        if ($filter->documentUuid !== null && $event->documentUuid !== $filter->documentUuid) {
            return false;
        }

        if ($filter->documentExternalId !== null && $event->documentExternalId !== $filter->documentExternalId) {
            return false;
        }

        if ($filter->since !== null && $event->receivedAt < $filter->since && $event->occurredAt < $filter->since) {
            return false;
        }

        return true;
    }
}
