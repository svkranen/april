<?php

namespace App\Intelligence\Application;

final readonly class JourneyDocumentCandidateResult
{
    /**
     * @param array<int, string> $matchProcessKeys
     * @param array<int, ProcessDocumentRef> $documentRefs
     * @param array<int, string> $warnings
     */
    public function __construct(
        public array $matchProcessKeys,
        public array $documentRefs,
        public array $warnings = []
    ) {
    }
}
