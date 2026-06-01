<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ContextSnapshot;

interface ContextSnapshotHistoryProvider
{
    /**
     * @return array<int, ContextSnapshot>
     */
    public function snapshotsForDocument(string $documentUuid, string $processKey): array;
}
