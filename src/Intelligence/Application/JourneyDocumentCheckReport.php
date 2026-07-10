<?php

namespace App\Intelligence\Application;

final readonly class JourneyDocumentCheckReport
{
    /**
     * @param array<int, string> $matchProcessKeys
     * @param array<int, string> $warnings
     * @param array<int, JourneyDocumentCheckRow> $rows
     */
    public function __construct(
        public string $journeyKey,
        public array $matchProcessKeys,
        public array $warnings,
        public array $rows
    ) {
    }
}
