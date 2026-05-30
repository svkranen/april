<?php

namespace App\Intelligence\Domain;

final readonly class ProcessTemplateSuggestionResult
{
    /**
     * @param array<int, string> $usedDocumentUuids
     * @param array<int, SuggestedTransition> $transitionSuggestions
     * @param array<int, ProcessTemplateSuggestionWarning> $warnings
     * @param array<int, ProcessTemplateSuggestionNote> $suggestions
     */
    public function __construct(
        public ProcessTemplate $template,
        public array $usedDocumentUuids = [],
        public array $transitionSuggestions = [],
        public array $warnings = [],
        public array $suggestions = []
    ) {
    }
}
