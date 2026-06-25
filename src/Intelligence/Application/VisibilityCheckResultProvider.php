<?php

namespace App\Intelligence\Application;

interface VisibilityCheckResultProvider
{
    /**
     * @return array<int, VisibilityCheckResultRecord>
     */
    public function findByDocument(string $documentUuid, ?string $processKey = null): array;
}
