<?php

namespace App\Intelligence\Infrastructure\Doctrine\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'intelligence_process_instance')]
#[ORM\Index(columns: ['process_key'], name: 'idx_intelligence_process_instance_process_key')]
#[ORM\Index(columns: ['document_uuid'], name: 'idx_intelligence_process_instance_document_uuid')]
#[ORM\Index(columns: ['document_version'], name: 'idx_intelligence_process_instance_document_version')]
#[ORM\Index(columns: ['current_step_key'], name: 'idx_intelligence_process_instance_current_step_key')]
#[ORM\Index(columns: ['status'], name: 'idx_intelligence_process_instance_status')]
#[ORM\UniqueConstraint(
    name: 'uniq_intelligence_process_instance_identity',
    columns: ['source_system', 'process_key', 'template_version', 'document_identity_key', 'document_version']
)]
class ProcessInstanceEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'source_system', type: 'string', length: 64)]
    private string $sourceSystem;

    #[ORM\Column(name: 'process_key', type: 'string', length: 255)]
    private string $processKey;

    #[ORM\Column(name: 'template_version', type: 'string', length: 128)]
    private string $templateVersion;

    #[ORM\Column(name: 'document_external_id', type: 'string', length: 255)]
    private string $documentExternalId;

    #[ORM\Column(name: 'document_uuid', type: 'string', length: 255, nullable: true)]
    private ?string $documentUuid;

    #[ORM\Column(name: 'document_identity_key', type: 'string', length: 320)]
    private string $documentIdentityKey;

    #[ORM\Column(name: 'document_version', type: 'integer')]
    private int $documentVersion;

    #[ORM\Column(type: 'string', length: 64)]
    private string $status;

    #[ORM\Column(name: 'current_step_key', type: 'string', length: 255)]
    private string $currentStepKey;

    #[ORM\Column(name: 'started_at', type: 'datetime_immutable')]
    private DateTimeImmutable $startedAt;

    #[ORM\Column(name: 'last_event_at', type: 'datetime_immutable')]
    private DateTimeImmutable $lastEventAt;

    #[ORM\Column(name: 'ended_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $endedAt;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setSourceSystem(string $sourceSystem): void
    {
        $this->sourceSystem = $sourceSystem;
    }

    public function getSourceSystem(): string
    {
        return $this->sourceSystem;
    }

    public function setProcessKey(string $processKey): void
    {
        $this->processKey = $processKey;
    }

    public function getProcessKey(): string
    {
        return $this->processKey;
    }

    public function setTemplateVersion(string $templateVersion): void
    {
        $this->templateVersion = $templateVersion;
    }

    public function getTemplateVersion(): string
    {
        return $this->templateVersion;
    }

    public function setDocumentExternalId(string $documentExternalId): void
    {
        $this->documentExternalId = $documentExternalId;
    }

    public function getDocumentExternalId(): string
    {
        return $this->documentExternalId;
    }

    public function setDocumentUuid(?string $documentUuid): void
    {
        $this->documentUuid = $documentUuid;
        $this->documentIdentityKey = self::documentIdentityKey($documentUuid, $this->documentExternalId);
    }

    public function getDocumentUuid(): ?string
    {
        return $this->documentUuid;
    }

    public function setDocumentIdentityKey(string $documentIdentityKey): void
    {
        $this->documentIdentityKey = $documentIdentityKey;
    }

    public function getDocumentIdentityKey(): string
    {
        return $this->documentIdentityKey;
    }

    public function setDocumentVersion(int $documentVersion): void
    {
        $this->documentVersion = $documentVersion;
    }

    public function getDocumentVersion(): int
    {
        return $this->documentVersion;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setCurrentStepKey(string $currentStepKey): void
    {
        $this->currentStepKey = $currentStepKey;
    }

    public function getCurrentStepKey(): string
    {
        return $this->currentStepKey;
    }

    public function setStartedAt(DateTimeImmutable $startedAt): void
    {
        $this->startedAt = $startedAt;
    }

    public function getStartedAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setLastEventAt(DateTimeImmutable $lastEventAt): void
    {
        $this->lastEventAt = $lastEventAt;
    }

    public function getLastEventAt(): DateTimeImmutable
    {
        return $this->lastEventAt;
    }

    public function setEndedAt(?DateTimeImmutable $endedAt): void
    {
        $this->endedAt = $endedAt;
    }

    public function getEndedAt(): ?DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setUpdatedAt(DateTimeImmutable $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public static function documentIdentityKey(?string $documentUuid, string $documentExternalId): string
    {
        return $documentUuid !== null && $documentUuid !== '' ? 'uuid:'.$documentUuid : 'external:'.$documentExternalId;
    }
}
