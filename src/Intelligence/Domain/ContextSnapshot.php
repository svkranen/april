<?php

namespace App\Intelligence\Domain;

use DateTimeImmutable;

final readonly class ContextSnapshot
{
    /**
     * @param array<string, mixed> $attributes
     * @param array<int, string> $warnings
     */
    public function __construct(
        public DocumentRef $document,
        public DateTimeImmutable $capturedAt,
        public array $attributes = [],
        public array $warnings = [],
        public ?string $processKey = null,
        public ?string $externalEventKey = null
    ) {
    }
}
