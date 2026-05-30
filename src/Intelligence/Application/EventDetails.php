<?php

namespace App\Intelligence\Application;

use DateTimeImmutable;

final readonly class EventDetails
{
    /**
     * @param array<string, mixed> $rawPayload
     * @param array<string, mixed> $normalizedEvent
     * @param array<int, EventContextSnapshotDetails> $contextSnapshots
     */
    public function __construct(
        public ?int $id,
        public string $externalEventKey,
        public string $sourceSystem,
        public string $processKey,
        public string $eventKey,
        public string $stepKey,
        public string $documentExternalId,
        public ?string $documentUuid,
        public int $documentVersion,
        public ?string $actorRef,
        public ?int $processInstanceId,
        public DateTimeImmutable $occurredAt,
        public DateTimeImmutable $receivedAt,
        public array $rawPayload,
        public array $normalizedEvent,
        public array $contextSnapshots = []
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function baseData(): array
    {
        return [
            'id' => $this->id,
            'externalEventKey' => $this->externalEventKey,
            'sourceSystem' => $this->sourceSystem,
            'processKey' => $this->processKey,
            'eventKey' => $this->eventKey,
            'stepKey' => $this->stepKey,
            'documentExternalId' => $this->documentExternalId,
            'documentUuid' => $this->documentUuid,
            'documentVersion' => $this->documentVersion,
            'actorRef' => $this->actorRef,
            'processInstanceId' => $this->processInstanceId,
            'occurredAt' => $this->occurredAt->format(DATE_ATOM),
            'receivedAt' => $this->receivedAt->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function contextSnapshotsArray(): array
    {
        return array_map(
            static fn (EventContextSnapshotDetails $snapshot): array => $snapshot->toArray(),
            $this->contextSnapshots
        );
    }
}
