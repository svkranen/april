<?php

namespace App\Intelligence\Domain;

use DateTimeImmutable;

final readonly class ContextSnapshot
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public DocumentRef $document,
        public DateTimeImmutable $capturedAt,
        public array $attributes = []
    ) {
    }
}
