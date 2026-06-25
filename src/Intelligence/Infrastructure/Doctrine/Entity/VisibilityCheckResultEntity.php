<?php

namespace App\Intelligence\Infrastructure\Doctrine\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'intelligence_visibility_check_result')]
#[ORM\Index(columns: ['document_uuid', 'process_key'], name: 'idx_intelligence_visibility_document_process')]
#[ORM\Index(columns: ['process_key', 'source_system', 'step_key', 'event_phase'], name: 'idx_intelligence_visibility_process_step_phase')]
#[ORM\Index(columns: ['status', 'checked_at'], name: 'idx_intelligence_visibility_status_checked')]
#[ORM\Index(columns: ['external_event_key', 'check_key'], name: 'idx_intelligence_visibility_external_check')]
class VisibilityCheckResultEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'process_event_id', type: 'integer', nullable: true)]
    private ?int $processEventId = null;

    #[ORM\Column(name: 'process_instance_id', type: 'integer', nullable: true)]
    private ?int $processInstanceId = null;

    #[ORM\Column(name: 'external_event_key', type: 'string', length: 255, nullable: true)]
    private ?string $externalEventKey = null;

    #[ORM\Column(name: 'document_uuid', type: 'string', length: 255)]
    private string $documentUuid;

    #[ORM\Column(name: 'document_version', type: 'integer', nullable: true)]
    private ?int $documentVersion = null;

    #[ORM\Column(name: 'process_key', type: 'string', length: 255)]
    private string $processKey;

    #[ORM\Column(name: 'source_system', type: 'string', length: 64)]
    private string $sourceSystem;

    #[ORM\Column(name: 'step_key', type: 'string', length: 255)]
    private string $stepKey;

    #[ORM\Column(name: 'event_phase', type: 'string', length: 16)]
    private string $eventPhase;

    #[ORM\Column(name: 'check_key', type: 'string', length: 255)]
    private string $checkKey;

    #[ORM\Column(name: 'profile_key', type: 'string', length: 255, nullable: true)]
    private ?string $profileKey = null;

    #[ORM\Column(name: 'probe_key', type: 'string', length: 255)]
    private string $probeKey;

    #[ORM\Column(name: 'probe_type', type: 'string', length: 255, nullable: true)]
    private ?string $probeType = null;

    #[ORM\Column(name: 'probe_ref', type: 'string', length: 255, nullable: true)]
    private ?string $probeRef = null;

    #[ORM\Column(type: 'string', length: 16)]
    private string $expected;

    #[ORM\Column(type: 'string', length: 16)]
    private string $actual;

    #[ORM\Column(type: 'string', length: 32)]
    private string $status;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(name: 'checked_at', type: 'datetime_immutable')]
    private DateTimeImmutable $checkedAt;

    #[ORM\Column(name: 'attempt_no', type: 'integer', options: ['default' => 1])]
    private int $attemptNo = 1;

    #[ORM\Column(name: 'is_final', type: 'boolean', options: ['default' => true])]
    private bool $isFinal = true;

    #[ORM\Column(name: 'document_count', type: 'integer', nullable: true)]
    private ?int $documentCount = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'raw_result_json', type: 'json', nullable: true, options: ['jsonb' => true])]
    private ?array $rawResultJson = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'details_json', type: 'json', nullable: true, options: ['jsonb' => true])]
    private ?array $detailsJson = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function getId(): ?int { return $this->id; }
    public function setProcessEventId(?int $processEventId): void { $this->processEventId = $processEventId; }
    public function getProcessEventId(): ?int { return $this->processEventId; }
    public function setProcessInstanceId(?int $processInstanceId): void { $this->processInstanceId = $processInstanceId; }
    public function getProcessInstanceId(): ?int { return $this->processInstanceId; }
    public function setExternalEventKey(?string $externalEventKey): void { $this->externalEventKey = $externalEventKey; }
    public function getExternalEventKey(): ?string { return $this->externalEventKey; }
    public function setDocumentUuid(string $documentUuid): void { $this->documentUuid = $documentUuid; }
    public function getDocumentUuid(): string { return $this->documentUuid; }
    public function setDocumentVersion(?int $documentVersion): void { $this->documentVersion = $documentVersion; }
    public function getDocumentVersion(): ?int { return $this->documentVersion; }
    public function setProcessKey(string $processKey): void { $this->processKey = $processKey; }
    public function getProcessKey(): string { return $this->processKey; }
    public function setSourceSystem(string $sourceSystem): void { $this->sourceSystem = $sourceSystem; }
    public function getSourceSystem(): string { return $this->sourceSystem; }
    public function setStepKey(string $stepKey): void { $this->stepKey = $stepKey; }
    public function getStepKey(): string { return $this->stepKey; }
    public function setEventPhase(string $eventPhase): void { $this->eventPhase = $eventPhase; }
    public function getEventPhase(): string { return $this->eventPhase; }
    public function setCheckKey(string $checkKey): void { $this->checkKey = $checkKey; }
    public function getCheckKey(): string { return $this->checkKey; }
    public function setProfileKey(?string $profileKey): void { $this->profileKey = $profileKey; }
    public function getProfileKey(): ?string { return $this->profileKey; }
    public function setProbeKey(string $probeKey): void { $this->probeKey = $probeKey; }
    public function getProbeKey(): string { return $this->probeKey; }
    public function setProbeType(?string $probeType): void { $this->probeType = $probeType; }
    public function getProbeType(): ?string { return $this->probeType; }
    public function setProbeRef(?string $probeRef): void { $this->probeRef = $probeRef; }
    public function getProbeRef(): ?string { return $this->probeRef; }
    public function setExpected(string $expected): void { $this->expected = $expected; }
    public function getExpected(): string { return $this->expected; }
    public function setActual(string $actual): void { $this->actual = $actual; }
    public function getActual(): string { return $this->actual; }
    public function setStatus(string $status): void { $this->status = $status; }
    public function getStatus(): string { return $this->status; }
    public function setReason(?string $reason): void { $this->reason = $reason; }
    public function getReason(): ?string { return $this->reason; }
    public function setCheckedAt(DateTimeImmutable $checkedAt): void { $this->checkedAt = $checkedAt; }
    public function getCheckedAt(): DateTimeImmutable { return $this->checkedAt; }
    public function setAttemptNo(int $attemptNo): void { $this->attemptNo = $attemptNo; }
    public function getAttemptNo(): int { return $this->attemptNo; }
    public function setIsFinal(bool $isFinal): void { $this->isFinal = $isFinal; }
    public function isFinal(): bool { return $this->isFinal; }
    public function setDocumentCount(?int $documentCount): void { $this->documentCount = $documentCount; }
    public function getDocumentCount(): ?int { return $this->documentCount; }
    /** @param array<string, mixed>|null $rawResultJson */
    public function setRawResultJson(?array $rawResultJson): void { $this->rawResultJson = $rawResultJson; }
    /** @return array<string, mixed>|null */
    public function getRawResultJson(): ?array { return $this->rawResultJson; }
    /** @param array<string, mixed>|null $detailsJson */
    public function setDetailsJson(?array $detailsJson): void { $this->detailsJson = $detailsJson; }
    /** @return array<string, mixed>|null */
    public function getDetailsJson(): ?array { return $this->detailsJson; }
    public function setCreatedAt(DateTimeImmutable $createdAt): void { $this->createdAt = $createdAt; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
}
