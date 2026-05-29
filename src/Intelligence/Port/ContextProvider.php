<?php

namespace App\Intelligence\Port;

use App\Intelligence\Domain\DocumentRef;

interface ContextProvider
{
    /**
     * @param array<int, string> $fields
     * @return array<string, mixed>
     */
    public function loadAttributes(DocumentRef $document, array $fields): array;
}
