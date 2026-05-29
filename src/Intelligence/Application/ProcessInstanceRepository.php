<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessInstance;

interface ProcessInstanceRepository
{
    public function findByIdentity(
        string $sourceSystem,
        ?string $documentUuid,
        string $documentExternalId,
        int $documentVersion,
        string $processKey,
        string $templateVersion
    ): ?ProcessInstance;

    public function save(ProcessInstance $instance): ProcessInstance;

    public function count(): int;
}
