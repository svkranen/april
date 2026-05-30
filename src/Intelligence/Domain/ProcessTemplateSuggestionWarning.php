<?php

namespace App\Intelligence\Domain;

final readonly class ProcessTemplateSuggestionWarning
{
    /**
     * @param array<int, string> $documentUuids
     */
    public function __construct(
        public string $type,
        public string $message,
        public array $documentUuids = []
    ) {
    }
}
