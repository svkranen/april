<?php

namespace App\Intelligence\Application;

/**
 * Read model for the "Änderungsvorschläge / Modellierungsentscheidungen" section
 * of the template assistant. Separated from the technical consistency view so the
 * page can clearly distinguish "technically consistent" from "ready as a Soll
 * model". Suggestions require on-demand findings; without them the section only
 * offers a hint/link instead of running an expensive runtime check.
 */
final readonly class TemplateModelingSuggestionsView
{
    /**
     * @param array<int, TemplateModelingSuggestion> $suggestions
     */
    public function __construct(
        public bool $withFindings,
        public array $suggestions,
        public int $totalDocuments,
        public int $processedDocuments,
        public bool $limitReached
    ) {
    }

    /** Findings were not computed (no ?withFindings=1): nothing to derive yet. */
    public static function notComputed(): self
    {
        return new self(false, [], 0, 0, false);
    }

    public function hasSuggestions(): bool
    {
        return $this->suggestions !== [];
    }
}
