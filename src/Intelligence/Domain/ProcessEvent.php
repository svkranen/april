<?php

namespace App\Intelligence\Domain;

use DateTimeImmutable;

final readonly class ProcessEvent
{
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
        public DateTimeImmutable $occurredAt,
        public DateTimeImmutable $receivedAt,
        public string $rawPayloadJson,
        public string $normalizedEventJson,
        public ?int $processInstanceId = null,
        public string $eventPhase = 'after'
    ) {
    }

    public function withId(int $id): self
    {
        return new self(
            $id,
            $this->externalEventKey,
            $this->sourceSystem,
            $this->processKey,
            $this->eventKey,
            $this->stepKey,
            $this->documentExternalId,
            $this->documentUuid,
            $this->documentVersion,
            $this->actorRef,
            $this->occurredAt,
            $this->receivedAt,
            $this->rawPayloadJson,
            $this->normalizedEventJson,
            $this->processInstanceId,
            $this->eventPhase
        );
    }

    public function withProcessInstanceId(int $processInstanceId): self
    {
        return new self(
            $this->id,
            $this->externalEventKey,
            $this->sourceSystem,
            $this->processKey,
            $this->eventKey,
            $this->stepKey,
            $this->documentExternalId,
            $this->documentUuid,
            $this->documentVersion,
            $this->actorRef,
            $this->occurredAt,
            $this->receivedAt,
            $this->rawPayloadJson,
            $this->normalizedEventJson,
            $processInstanceId,
            $this->eventPhase
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'externalEventKey' => $this->externalEventKey,
            'sourceSystem' => $this->sourceSystem,
            'processKey' => $this->processKey,
            'eventKey' => $this->eventKey,
            'stepKey' => $this->stepKey,
            'eventPhase' => $this->eventPhase,
            'documentExternalId' => $this->documentExternalId,
            'documentUuid' => $this->documentUuid,
            'documentVersion' => $this->documentVersion,
            'actorRef' => $this->actorRef,
            'occurredAt' => $this->occurredAt->format(DateTimeImmutable::ATOM),
            'receivedAt' => $this->receivedAt->format(DateTimeImmutable::ATOM),
            'rawPayloadJson' => $this->rawPayloadJson,
            'normalizedEventJson' => $this->normalizedEventJson,
            'processInstanceId' => $this->processInstanceId,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            isset($data['id']) ? (int) $data['id'] : null,
            (string) $data['externalEventKey'],
            (string) $data['sourceSystem'],
            (string) $data['processKey'],
            (string) $data['eventKey'],
            (string) ($data['stepKey'] ?? $data['eventKey']),
            (string) $data['documentExternalId'],
            isset($data['documentUuid']) ? (string) $data['documentUuid'] : null,
            (int) $data['documentVersion'],
            isset($data['actorRef']) ? (string) $data['actorRef'] : null,
            new DateTimeImmutable((string) $data['occurredAt']),
            new DateTimeImmutable((string) $data['receivedAt']),
            (string) $data['rawPayloadJson'],
            (string) $data['normalizedEventJson'],
            isset($data['processInstanceId']) ? (int) $data['processInstanceId'] : null,
            (string) ($data['eventPhase'] ?? 'after')
        );
    }
}
