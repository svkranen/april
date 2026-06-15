<?php

namespace App\Intelligence\Application;

final readonly class VisibilityCheckEvaluationResult
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public string $documentUuid,
        public string $processKey,
        public string $stepKey,
        public string $eventPhase,
        public string $checkKey,
        public string $profileKey,
        public string $probeKey,
        public string $expected,
        public string $actual,
        public string $status,
        public ?string $reason = null,
        public array $details = []
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'documentUuid' => $this->documentUuid,
            'processKey' => $this->processKey,
            'stepKey' => $this->stepKey,
            'eventPhase' => $this->eventPhase,
            'checkKey' => $this->checkKey,
            'profileKey' => $this->profileKey,
            'probeKey' => $this->probeKey,
            'expected' => $this->expected,
            'actual' => $this->actual,
            'status' => $this->status,
            'reason' => $this->reason,
            'details' => $this->details,
        ];
    }
}
