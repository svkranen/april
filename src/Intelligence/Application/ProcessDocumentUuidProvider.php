<?php

namespace App\Intelligence\Application;

use DateTimeImmutable;

interface ProcessDocumentUuidProvider
{
    /**
     * @return array<int, string>
     */
    public function documentUuidsForProcess(string $processKey, ?DateTimeImmutable $since = null, ?int $limit = null): array;
}
