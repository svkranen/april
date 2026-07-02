<?php

namespace App\Intelligence\Application;

interface ProcessKeyOverviewProvider
{
    /**
     * @return array<int, ProcessKeyOverviewRow>
     */
    public function processKeys(): array;

    /**
     * @return array<int, ProcessKeyDocumentOverviewRow>
     */
    public function documentsForProcessKey(string $processKey, ?int $limit = null): array;
}
