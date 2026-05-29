<?php

namespace App\Intelligence\Domain;

final readonly class DocumentRef
{
    public function __construct(
        public string $sourceSystem,
        public string $externalId,
        public ?string $externalUuid,
        public int $version
    ) {
    }
}
