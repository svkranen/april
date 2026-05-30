<?php

namespace App\Intelligence\Domain;

final readonly class ProcessTemplateFieldMapping
{
    public function __construct(
        public string $fieldKey,
        public string $source,
        public ?string $tagName = null,
        public ?string $tagId = null,
        public ?string $valueType = null
    ) {
    }
}
