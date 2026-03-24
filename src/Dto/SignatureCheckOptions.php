<?php

namespace App\Dto;

class SignatureCheckOptions
{
    public function __construct(
        public string $magnetId,
        public string $requiredTagId,
        public string $confirmedTagId,
        public ?string $connectionId = null,
        public ?string $token = null,
        public ?string $apiUsername = null,
        public ?string $apiPassword = null,
        public ?string $baseUri = null,
        public ?int $credentialId = null,
        public ?string $resultAttributeId = null,
        public ?string $completeStampId = null,
        public ?string $incompleteStampId = null,
        public bool $dryRun = false,
        public bool $useCheckpoint = false,
        public ?string $checkpointName = null,
        public int $batchSize = 200,
        public ?\DateTimeInterface $modifiedSince = null
    ) {
    }

    public function checkpointKey(): string
    {
        return $this->checkpointName ?: $this->magnetId;
    }
}
