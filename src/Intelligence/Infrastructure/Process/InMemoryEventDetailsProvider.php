<?php

namespace App\Intelligence\Infrastructure\Process;

use App\Intelligence\Application\EventContextSnapshotDetails;
use App\Intelligence\Application\EventDetails;
use App\Intelligence\Application\EventDetailsProvider;
use App\Intelligence\Domain\ContextSnapshot;
use App\Intelligence\Domain\ProcessEventRecord;

final class InMemoryEventDetailsProvider implements EventDetailsProvider
{
    /**
     * @param array<int, ProcessEventRecord> $events
     * @param array<int, ContextSnapshot> $snapshots
     */
    public function __construct(
        private readonly array $events = [],
        private readonly array $snapshots = []
    ) {
    }

    public function find(int $eventId): ?EventDetails
    {
        foreach ($this->events as $event) {
            if ($event->id !== $eventId) {
                continue;
            }

            return new EventDetails(
                $event->id,
                $event->externalEventKey,
                $event->sourceSystem,
                $event->processKey,
                $event->eventKey,
                $event->stepKey,
                $event->documentExternalId,
                $event->documentUuid,
                $event->documentVersion,
                $event->actorRef,
                $event->processInstanceId,
                $event->occurredAt,
                $event->receivedAt,
                $this->decodeJson($event->rawPayloadJson),
                $this->decodeJson($event->normalizedEventJson),
                $this->contextSnapshots($event->externalEventKey)
            );
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<int, EventContextSnapshotDetails>
     */
    private function contextSnapshots(string $externalEventKey): array
    {
        $snapshots = [];
        foreach ($this->snapshots as $snapshot) {
            if ($snapshot->externalEventKey !== $externalEventKey) {
                continue;
            }

            $snapshots[] = new EventContextSnapshotDetails(
                null,
                $snapshot->capturedAt,
                $snapshot->attributes,
                $snapshot->warnings
            );
        }

        return $snapshots;
    }
}
