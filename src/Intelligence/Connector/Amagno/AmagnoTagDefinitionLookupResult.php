<?php

namespace App\Intelligence\Connector\Amagno;

final readonly class AmagnoTagDefinitionLookupResult
{
    public function __construct(
        public ?string $tagDefinitionId,
        public ?string $definitionType = null,
        public ?string $warning = null
    ) {
    }
}
