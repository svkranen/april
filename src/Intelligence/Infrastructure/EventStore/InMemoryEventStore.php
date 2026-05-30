<?php

namespace App\Intelligence\Infrastructure\EventStore;

use App\Intelligence\Domain\ProcessEventRecord;
use App\Intelligence\Port\EventStore;
use App\Intelligence\Port\EventStoreResult;

final class InMemoryEventStore implements EventStore
{
    /** @var array<string, ProcessEventRecord> */
    private array $eventsByExternalKey = [];

    public function append(ProcessEventRecord $event): EventStoreResult
    {
        if (isset($this->eventsByExternalKey[$event->externalEventKey])) {
            return new EventStoreResult($this->eventsByExternalKey[$event->externalEventKey], true);
        }

        $stored = $event->id === null ? $event->withId(count($this->eventsByExternalKey) + 1) : $event;
        $this->eventsByExternalKey[$stored->externalEventKey] = $stored;

        return new EventStoreResult($stored, false);
    }

    public function count(): int
    {
        return count($this->eventsByExternalKey);
    }

    public function attachProcessInstance(ProcessEventRecord $event, int $processInstanceId): ProcessEventRecord
    {
        $stored = ($this->eventsByExternalKey[$event->externalEventKey] ?? $event)->withProcessInstanceId($processInstanceId);
        $this->eventsByExternalKey[$stored->externalEventKey] = $stored;

        return $stored;
    }

    /**
     * @return array<int, ProcessEventRecord>
     */
    public function all(): array
    {
        return array_values($this->eventsByExternalKey);
    }
}
