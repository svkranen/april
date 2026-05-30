<?php

namespace App\Intelligence\Application;

use App\Intelligence\Port\ContextProvider;

final readonly class ContextProviderSelection
{
    /**
     * @param array<int, string> $requiredFields
     */
    public function __construct(
        public ContextProvider $contextProvider,
        public array $requiredFields
    ) {
    }
}
