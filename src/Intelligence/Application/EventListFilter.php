<?php

namespace App\Intelligence\Application;

use DateTimeImmutable;

final readonly class EventListFilter
{
    public function __construct(
        public int $limit = 20,
        public ?string $processKey = null,
        public ?string $documentUuid = null,
        public ?string $documentExternalId = null,
        public ?DateTimeImmutable $since = null
    ) {
    }
}
