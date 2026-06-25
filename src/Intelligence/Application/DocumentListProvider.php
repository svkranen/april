<?php

namespace App\Intelligence\Application;

interface DocumentListProvider
{
    /**
     * Lists documents APRIL already knows process events for, most recent first.
     *
     * @return array<int, DocumentListRow>
     */
    public function documentsForProcess(string $processKey, ?int $limit = null): array;
}
