<?php

namespace App\Intelligence\Domain;

use DateTimeImmutable;

final readonly class IncomingEvent
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_DEAD = 'dead';

    /**
     * @param array<string, mixed>|null $normalizedPayloadJson
     */
    public function __construct(
        public ?int $id,
        public string $processKey,
        public string $connectorType,
        public ?string $connectionName,
        public ?string $documentId,
        public ?string $documentUuid,
        public ?string $eventKey,
        public ?string $externalEventKey,
        public ?DateTimeImmutable $occurredAt,
        public DateTimeImmutable $receivedAt,
        public ?string $contentType,
        public string $rawPayload,
        public ?array $normalizedPayloadJson = null,
        public string $status = self::STATUS_PENDING,
        public int $retryCount = 0,
        public ?string $lastError = null,
        public ?DateTimeImmutable $processedAt = null,
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $updatedAt = null
    ) {
    }

    public function withId(int $id, ?DateTimeImmutable $createdAt = null, ?DateTimeImmutable $updatedAt = null): self
    {
        return new self(
            $id,
            $this->processKey,
            $this->connectorType,
            $this->connectionName,
            $this->documentId,
            $this->documentUuid,
            $this->eventKey,
            $this->externalEventKey,
            $this->occurredAt,
            $this->receivedAt,
            $this->contentType,
            $this->rawPayload,
            $this->normalizedPayloadJson,
            $this->status,
            $this->retryCount,
            $this->lastError,
            $this->processedAt,
            $createdAt ?? $this->createdAt,
            $updatedAt ?? $this->updatedAt
        );
    }
}
