<?php

namespace App\Intelligence\Infrastructure\Doctrine\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'intelligence_context_snapshot')]
#[ORM\Index(columns: ['process_key'], name: 'idx_intelligence_context_snapshot_process_key')]
#[ORM\Index(columns: ['document_uuid'], name: 'idx_intelligence_context_snapshot_document_uuid')]
#[ORM\Index(columns: ['document_version'], name: 'idx_intelligence_context_snapshot_document_version')]
#[ORM\Index(columns: ['process_instance_id'], name: 'idx_intelligence_context_snapshot_process_instance_id')]
class ContextSnapshotEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'source_system', type: 'string', length: 64)]
    private string $sourceSystem;

    #[ORM\Column(name: 'document_external_id', type: 'string', length: 255)]
    private string $documentExternalId;

    #[ORM\Column(name: 'document_uuid', type: 'string', length: 255, nullable: true)]
    private ?string $documentUuid;

    #[ORM\Column(name: 'document_version', type: 'integer')]
    private int $documentVersion;

    #[ORM\Column(name: 'process_key', type: 'string', length: 255, nullable: true)]
    private ?string $processKey;

    #[ORM\Column(name: 'external_event_key', type: 'string', length: 255, nullable: true)]
    private ?string $externalEventKey;

    #[ORM\ManyToOne(targetEntity: ProcessInstanceEntity::class)]
    #[ORM\JoinColumn(name: 'process_instance_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?ProcessInstanceEntity $processInstance = null;

    #[ORM\Column(name: 'captured_at', type: 'datetime_immutable')]
    private DateTimeImmutable $capturedAt;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'context_json', type: 'json', options: ['jsonb' => true])]
    private array $contextJson = [];

    /** @var array<int, string> */
    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    private array $warnings = [];

    public function getId(): ?int { return $this->id; }
    public function setSourceSystem(string $sourceSystem): void { $this->sourceSystem = $sourceSystem; }
    public function getSourceSystem(): string { return $this->sourceSystem; }
    public function setDocumentExternalId(string $documentExternalId): void { $this->documentExternalId = $documentExternalId; }
    public function getDocumentExternalId(): string { return $this->documentExternalId; }
    public function setDocumentUuid(?string $documentUuid): void { $this->documentUuid = $documentUuid; }
    public function getDocumentUuid(): ?string { return $this->documentUuid; }
    public function setDocumentVersion(int $documentVersion): void { $this->documentVersion = $documentVersion; }
    public function getDocumentVersion(): int { return $this->documentVersion; }
    public function setProcessKey(?string $processKey): void { $this->processKey = $processKey; }
    public function getProcessKey(): ?string { return $this->processKey; }
    public function setExternalEventKey(?string $externalEventKey): void { $this->externalEventKey = $externalEventKey; }
    public function getExternalEventKey(): ?string { return $this->externalEventKey; }
    public function setProcessInstance(?ProcessInstanceEntity $processInstance): void { $this->processInstance = $processInstance; }
    public function getProcessInstance(): ?ProcessInstanceEntity { return $this->processInstance; }
    public function setCapturedAt(DateTimeImmutable $capturedAt): void { $this->capturedAt = $capturedAt; }
    public function getCapturedAt(): DateTimeImmutable { return $this->capturedAt; }
    /** @param array<string, mixed> $contextJson */
    public function setContextJson(array $contextJson): void { $this->contextJson = $contextJson; }
    /** @return array<string, mixed> */
    public function getContextJson(): array { return $this->contextJson; }
    /** @param array<int, string> $warnings */
    public function setWarnings(array $warnings): void { $this->warnings = $warnings; }
    /** @return array<int, string> */
    public function getWarnings(): array { return $this->warnings; }
}
