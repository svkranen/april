<?php

namespace App\Intelligence\Connector\Amagno;

final readonly class AmagnoFieldMapping
{
    public function __construct(
        public string $fieldKey,
        public ?string $tagId = null,
        public ?string $tagName = null,
        public ?string $valueType = null
    ) {
    }
}
