<?php

namespace App\Intelligence\Application;

final readonly class JourneyTemplateMatchKeys
{
    /**
     * @param array<int, string> $processKeys
     * @param array<int, string> $warnings
     */
    public function __construct(
        public array $processKeys,
        public array $warnings = []
    ) {
    }

    public function isMatchable(): bool
    {
        return $this->processKeys !== [];
    }
}
