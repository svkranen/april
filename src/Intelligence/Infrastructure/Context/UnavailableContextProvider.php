<?php

namespace App\Intelligence\Infrastructure\Context;

use App\Intelligence\Application\ContextProviderWarningProvider;
use App\Intelligence\Domain\DocumentRef;
use App\Intelligence\Port\ContextProvider;

final readonly class UnavailableContextProvider implements ContextProvider, ContextProviderWarningProvider
{
    public function __construct(
        private string $warning
    ) {
    }

    public function loadAttributes(DocumentRef $document, array $fields): array
    {
        return [];
    }

    public function warnings(): array
    {
        return [$this->warning];
    }
}
