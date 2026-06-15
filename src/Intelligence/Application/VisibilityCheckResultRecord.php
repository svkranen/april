<?php

namespace App\Intelligence\Application;

use DateTimeImmutable;

final readonly class VisibilityCheckResultRecord
{
    /**
     * @param array<string, mixed>|null $details
     */
    public function __construct(
        public int $id,
        public string $documentUuid,
        public ?int $documentVersion,
        public string $processKey,
        public string $sourceSystem,
        public string $stepKey,
        public string $eventPhase,
        public string $checkKey,
        public ?string $profileKey,
        public string $probeKey,
        public ?string $probeType,
        public ?string $probeRef,
        public string $expected,
        public string $actual,
        public string $status,
        public ?string $reason,
        public DateTimeImmutable $checkedAt,
        public int $attemptNo,
        public bool $isFinal,
        public ?int $documentCount,
        public ?array $details
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'documentUuid' => $this->documentUuid,
            'documentVersion' => $this->documentVersion,
            'processKey' => $this->processKey,
            'sourceSystem' => $this->sourceSystem,
            'stepKey' => $this->stepKey,
            'eventPhase' => $this->eventPhase,
            'checkKey' => $this->checkKey,
            'profileKey' => $this->profileKey,
            'probeKey' => $this->probeKey,
            'probeType' => $this->probeType,
            'probeRef' => $this->probeRef,
            'expected' => $this->expected,
            'actual' => $this->actual,
            'status' => $this->status,
            'reason' => $this->reason,
            'checkedAt' => $this->checkedAt->format(DATE_ATOM),
            'attemptNo' => $this->attemptNo,
            'isFinal' => $this->isFinal,
            'documentCount' => $this->documentCount,
            'details' => $this->details,
        ];
    }
}
