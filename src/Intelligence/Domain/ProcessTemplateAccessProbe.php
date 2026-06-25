<?php

namespace App\Intelligence\Domain;

final readonly class ProcessTemplateAccessProbe
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public string $key,
        public string $sourceSystem,
        public string $type,
        public array $options = [],
        public ?int $maxDocuments = null,
        public ?string $description = null
    ) {
    }
}
