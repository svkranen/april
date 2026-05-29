<?php

namespace App\Intelligence\Infrastructure\EventStore;

use App\Intelligence\Domain\ProcessEvent;
use App\Intelligence\Port\EventStore;
use App\Intelligence\Port\EventStoreResult;

final class InMemoryEventStore implements EventStore
{
    /** @var array<string, ProcessEvent> */
    private array $eventsByExternalKey = [];

    public function append(ProcessEvent $event): EventStoreResult
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

    /**
     * @return array<int, ProcessEvent>
     */
    public function all(): array
    {
        return array_values($this->eventsByExternalKey);
    }
}
