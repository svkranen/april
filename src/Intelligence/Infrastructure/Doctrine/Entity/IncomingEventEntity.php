<?php

namespace App\Intelligence\Infrastructure\Doctrine\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'intelligence_incoming_event')]
#[ORM\Index(columns: ['status'], name: 'idx_intelligence_incoming_event_status')]
#[ORM\Index(columns: ['process_key'], name: 'idx_intelligence_incoming_event_process_key')]
#[ORM\Index(columns: ['received_at'], name: 'idx_intelligence_incoming_event_received_at')]
class IncomingEventEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'process_key', type: 'string', length: 255)]
    private string $processKey;

    #[ORM\Column(name: 'connector_type', type: 'string', length: 64)]
    private string $connectorType = 'amagno';

    #[ORM\Column(name: 'connection_name', type: 'string', length: 255, nullable: true)]
    private ?string $connectionName = null;

    #[ORM\Column(name: 'document_id', type: 'string', length: 255, nullable: true)]
    private ?string $documentId = null;

    #[ORM\Column(name: 'document_uuid', type: 'string', length: 255, nullable: true)]
    private ?string $documentUuid = null;

    #[ORM\Column(name: 'event_key', type: 'string', length: 255, nullable: true)]
    private ?string $eventKey = null;

    #[ORM\Column(name: 'external_event_key', type: 'string', length: 255, nullable: true)]
    private ?string $externalEventKey = null;

    #[ORM\Column(name: 'occurred_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $occurredAt = null;

    #[ORM\Column(name: 'received_at', type: 'datetime_immutable')]
    private DateTimeImmutable $receivedAt;

    #[ORM\Column(name: 'content_type', type: 'string', length: 255, nullable: true)]
    private ?string $contentType = null;

    #[ORM\Column(name: 'raw_payload', type: 'text')]
    private string $rawPayload;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'normalized_payload_json', type: 'json', nullable: true, options: ['jsonb' => true])]
    private ?array $normalizedPayloadJson = null;

    #[ORM\Column(type: 'string', length: 32)]
    private string $status;

    #[ORM\Column(name: 'retry_count', type: 'integer')]
    private int $retryCount = 0;

    #[ORM\Column(name: 'last_error', type: 'text', nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(name: 'processed_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $processedAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    public function getId(): ?int { return $this->id; }
    public function setProcessKey(string $processKey): void { $this->processKey = $processKey; }
    public function getProcessKey(): string { return $this->processKey; }
    public function setConnectorType(string $connectorType): void { $this->connectorType = $connectorType; }
    public function getConnectorType(): string { return $this->connectorType; }
    public function setConnectionName(?string $connectionName): void { $this->connectionName = $connectionName; }
    public function getConnectionName(): ?string { return $this->connectionName; }
    public function setDocumentId(?string $documentId): void { $this->documentId = $documentId; }
    public function getDocumentId(): ?string { return $this->documentId; }
    public function setDocumentUuid(?string $documentUuid): void { $this->documentUuid = $documentUuid; }
    public function getDocumentUuid(): ?string { return $this->documentUuid; }
    public function setEventKey(?string $eventKey): void { $this->eventKey = $eventKey; }
    public function getEventKey(): ?string { return $this->eventKey; }
    public function setExternalEventKey(?string $externalEventKey): void { $this->externalEventKey = $externalEventKey; }
    public function getExternalEventKey(): ?string { return $this->externalEventKey; }
    public function setOccurredAt(?DateTimeImmutable $occurredAt): void { $this->occurredAt = $occurredAt; }
    public function getOccurredAt(): ?DateTimeImmutable { return $this->occurredAt; }
    public function setReceivedAt(DateTimeImmutable $receivedAt): void { $this->receivedAt = $receivedAt; }
    public function getReceivedAt(): DateTimeImmutable { return $this->receivedAt; }
    public function setContentType(?string $contentType): void { $this->contentType = $contentType; }
    public function getContentType(): ?string { return $this->contentType; }
    public function setRawPayload(string $rawPayload): void { $this->rawPayload = $rawPayload; }
    public function getRawPayload(): string { return $this->rawPayload; }
    /** @param array<string, mixed>|null $normalizedPayloadJson */
    public function setNormalizedPayloadJson(?array $normalizedPayloadJson): void { $this->normalizedPayloadJson = $normalizedPayloadJson; }
    /** @return array<string, mixed>|null */
    public function getNormalizedPayloadJson(): ?array { return $this->normalizedPayloadJson; }
    public function setStatus(string $status): void { $this->status = $status; }
    public function getStatus(): string { return $this->status; }
    public function setRetryCount(int $retryCount): void { $this->retryCount = $retryCount; }
    public function getRetryCount(): int { return $this->retryCount; }
    public function setLastError(?string $lastError): void { $this->lastError = $lastError; }
    public function getLastError(): ?string { return $this->lastError; }
    public function setProcessedAt(?DateTimeImmutable $processedAt): void { $this->processedAt = $processedAt; }
    public function getProcessedAt(): ?DateTimeImmutable { return $this->processedAt; }
    public function setCreatedAt(DateTimeImmutable $createdAt): void { $this->createdAt = $createdAt; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function setUpdatedAt(DateTimeImmutable $updatedAt): void { $this->updatedAt = $updatedAt; }
    public function getUpdatedAt(): DateTimeImmutable { return $this->updatedAt; }
}
