<?php

namespace App\Intelligence\Application;

final readonly class VisibilityCheckResultSaveContext
{
    /**
     * @param array<string, mixed>|null $rawResult
     * @param array<string, mixed>|null $details
     */
    public function __construct(
        public ?int $processEventId = null,
        public ?int $processInstanceId = null,
        public ?string $externalEventKey = null,
        public ?int $documentVersion = null,
        public ?string $sourceSystem = null,
        public ?string $probeType = null,
        public ?string $probeRef = null,
        public ?int $attemptNo = 1,
        public bool $isFinal = true,
        public ?array $rawResult = null,
        public ?array $details = null
    ) {
    }
}
