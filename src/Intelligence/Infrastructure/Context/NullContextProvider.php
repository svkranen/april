<?php

namespace App\Intelligence\Infrastructure\Context;

use App\Intelligence\Domain\DocumentRef;
use App\Intelligence\Port\ContextProvider;

final class NullContextProvider implements ContextProvider
{
    public function loadAttributes(DocumentRef $document, array $fields): array
    {
        return [];
    }
}
