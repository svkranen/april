<?php

namespace App\Intelligence\Application;

use DateTimeImmutable;

final readonly class EventListRow
{
    public function __construct(
        public ?int $id,
        public string $externalEventKey,
        public string $processKey,
        public string $eventKey,
        public string $stepKey,
        public string $documentExternalId,
        public ?string $documentUuid,
        public int $documentVersion,
        public ?int $processInstanceId,
        public DateTimeImmutable $occurredAt,
        public DateTimeImmutable $receivedAt,
        public bool $duplicate = false
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'externalEventKey' => $this->externalEventKey,
            'processKey' => $this->processKey,
            'eventKey' => $this->eventKey,
            'stepKey' => $this->stepKey,
            'documentExternalId' => $this->documentExternalId,
            'documentUuid' => $this->documentUuid,
            'documentVersion' => $this->documentVersion,
            'processInstanceId' => $this->processInstanceId,
            'occurredAt' => $this->occurredAt->format(DATE_ATOM),
            'receivedAt' => $this->receivedAt->format(DATE_ATOM),
            'duplicate' => $this->duplicate,
        ];
    }
}
