<?php

namespace App\Intelligence\Infrastructure\Doctrine\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'intelligence_process_event')]
#[ORM\UniqueConstraint(name: 'uniq_intelligence_process_event_external_key', columns: ['external_event_key'])]
#[ORM\Index(columns: ['process_key'], name: 'idx_intelligence_process_event_process_key')]
#[ORM\Index(columns: ['document_uuid'], name: 'idx_intelligence_process_event_document_uuid')]
#[ORM\Index(columns: ['document_version'], name: 'idx_intelligence_process_event_document_version')]
#[ORM\Index(columns: ['process_instance_id'], name: 'idx_intelligence_process_event_process_instance_id')]
#[ORM\Index(columns: ['occurred_at'], name: 'idx_intelligence_process_event_occurred_at')]
class ProcessEventEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'external_event_key', type: 'string', length: 255)]
    private string $externalEventKey;

    #[ORM\Column(name: 'source_system', type: 'string', length: 64)]
    private string $sourceSystem;

    #[ORM\Column(name: 'process_key', type: 'string', length: 255)]
    private string $processKey;

    #[ORM\Column(name: 'event_key', type: 'string', length: 255)]
    private string $eventKey;

    #[ORM\Column(name: 'step_key', type: 'string', length: 255)]
    private string $stepKey;

    #[ORM\Column(name: 'event_phase', type: 'string', length: 16, options: ['default' => 'after'])]
    private string $eventPhase = 'after';

    #[ORM\Column(name: 'document_external_id', type: 'string', length: 255)]
    private string $documentExternalId;

    #[ORM\Column(name: 'document_uuid', type: 'string', length: 255, nullable: true)]
    private ?string $documentUuid;

    #[ORM\Column(name: 'document_version', type: 'integer')]
    private int $documentVersion;

    #[ORM\Column(name: 'actor_ref', type: 'string', length: 255, nullable: true)]
    private ?string $actorRef;

    #[ORM\Column(name: 'occurred_at', type: 'datetime_immutable')]
    private DateTimeImmutable $occurredAt;

    #[ORM\Column(name: 'received_at', type: 'datetime_immutable')]
    private DateTimeImmutable $receivedAt;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'raw_event_json', type: 'json', options: ['jsonb' => true])]
    private array $rawEventJson = [];

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'normalized_event_json', type: 'json', options: ['jsonb' => true])]
    private array $normalizedEventJson = [];

    #[ORM\ManyToOne(targetEntity: ProcessInstanceEntity::class)]
    #[ORM\JoinColumn(name: 'process_instance_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?ProcessInstanceEntity $processInstance = null;

    public function getId(): ?int { return $this->id; }
    public function setExternalEventKey(string $externalEventKey): void { $this->externalEventKey = $externalEventKey; }
    public function getExternalEventKey(): string { return $this->externalEventKey; }
    public function setSourceSystem(string $sourceSystem): void { $this->sourceSystem = $sourceSystem; }
    public function getSourceSystem(): string { return $this->sourceSystem; }
    public function setProcessKey(string $processKey): void { $this->processKey = $processKey; }
    public function getProcessKey(): string { return $this->processKey; }
    public function setEventKey(string $eventKey): void { $this->eventKey = $eventKey; }
    public function getEventKey(): string { return $this->eventKey; }
    public function setStepKey(string $stepKey): void { $this->stepKey = $stepKey; }
    public function getStepKey(): string { return $this->stepKey; }
    public function setEventPhase(string $eventPhase): void { $this->eventPhase = $eventPhase; }
    public function getEventPhase(): string { return $this->eventPhase; }
    public function setDocumentExternalId(string $documentExternalId): void { $this->documentExternalId = $documentExternalId; }
    public function getDocumentExternalId(): string { return $this->documentExternalId; }
    public function setDocumentUuid(?string $documentUuid): void { $this->documentUuid = $documentUuid; }
    public function getDocumentUuid(): ?string { return $this->documentUuid; }
    public function setDocumentVersion(int $documentVersion): void { $this->documentVersion = $documentVersion; }
    public function getDocumentVersion(): int { return $this->documentVersion; }
    public function setActorRef(?string $actorRef): void { $this->actorRef = $actorRef; }
    public function getActorRef(): ?string { return $this->actorRef; }
    public function setOccurredAt(DateTimeImmutable $occurredAt): void { $this->occurredAt = $occurredAt; }
    public function getOccurredAt(): DateTimeImmutable { return $this->occurredAt; }
    public function setReceivedAt(DateTimeImmutable $receivedAt): void { $this->receivedAt = $receivedAt; }
    public function getReceivedAt(): DateTimeImmutable { return $this->receivedAt; }
    /** @param array<string, mixed> $rawEventJson */
    public function setRawEventJson(array $rawEventJson): void { $this->rawEventJson = $rawEventJson; }
    /** @return array<string, mixed> */
    public function getRawEventJson(): array { return $this->rawEventJson; }
    /** @param array<string, mixed> $normalizedEventJson */
    public function setNormalizedEventJson(array $normalizedEventJson): void { $this->normalizedEventJson = $normalizedEventJson; }
    /** @return array<string, mixed> */
    public function getNormalizedEventJson(): array { return $this->normalizedEventJson; }
    public function setProcessInstance(?ProcessInstanceEntity $processInstance): void { $this->processInstance = $processInstance; }
    public function getProcessInstance(): ?ProcessInstanceEntity { return $this->processInstance; }
}
