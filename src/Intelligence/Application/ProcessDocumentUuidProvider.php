<?php

namespace App\Intelligence\Application;

use DateTimeImmutable;

interface ProcessDocumentUuidProvider
{
    /**
     * @return array<int, string>
     */
    public function documentUuidsForProcess(string $processKey, ?DateTimeImmutable $since = null, ?int $limit = null): array;

    /**
     * @return array<int, ProcessDocumentRef>
     */
    public function documentRefsForProcess(string $processKey, ?DateTimeImmutable $since = null, ?int $limit = null): array;
}
