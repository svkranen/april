<?php

namespace App\Intelligence\Domain;

use DateTimeImmutable;

final readonly class ProcessVersion
{
    public function __construct(
        public ?int $id,
        public string $processKey,
        public string $version,
        public DateTimeImmutable $validFrom,
        public ?string $description = null,
        public ?DateTimeImmutable $createdAt = null
    ) {
    }
}
