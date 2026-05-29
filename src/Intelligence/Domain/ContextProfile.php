<?php

namespace App\Intelligence\Domain;

final readonly class ContextProfile
{
    /**
     * @param array<int, string> $requiredFields
     */
    public function __construct(
        public string $processKey,
        public array $requiredFields
    ) {
    }
}
