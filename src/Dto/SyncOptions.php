<?php

namespace App\Dto;

class SyncOptions
{
    public function __construct(
        public string $magnetId,
        public string $exportTarget,
        public ?string $profile = null,
        public ?string $template = null,
        public string $system = 'onprem',
        public ?string $token = null,
        public ?string $vaultId = null,
        public ?string $localFolder = null,
        public ?string $ftpServer = null,
        public ?string $ftpUser = null,
        public ?string $ftpPassword = null,
        public ?string $ftpFolder = null,
        public ?string $dbHost = null,
        public ?string $dbName = null,
        public ?string $dbUser = null,
        public ?string $dbPassword = null,
        public ?string $stampId = null,
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
