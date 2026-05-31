<?php

namespace App\Intelligence\Application;

use App\Intelligence\Port\ContextProvider;
use App\Intelligence\Domain\ProcessTemplate;

final readonly class ContextProviderSelection
{
    /**
     * @param array<int, string> $requiredFields
     */
    public function __construct(
        public ContextProvider $contextProvider,
        public array $requiredFields,
        public ?ProcessTemplate $template = null
    ) {
    }
}
