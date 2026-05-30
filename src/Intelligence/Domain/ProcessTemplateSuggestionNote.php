<?php

namespace App\Intelligence\Domain;

final readonly class ProcessTemplateSuggestionNote
{
    /**
     * @param array<int, string> $documentUuids
     */
    public function __construct(
        public string $type,
        public string $message,
        public ?string $parallelGroupKey = null,
        public array $documentUuids = [],
        public ?float $confidence = null
    ) {
    }
}
