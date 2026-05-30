<?php

namespace App\Intelligence\Application;

final readonly class ProcessDocumentRef
{
    public function __construct(
        public string $documentUuid,
        public ?string $documentExternalId = null,
        public ?int $documentVersion = null
    ) {
    }
}
