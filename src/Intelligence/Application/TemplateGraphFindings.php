<?php

namespace App\Intelligence\Application;

/**
 * Aggregated, on-demand findings for the template Mermaid graph. Holds the
 * step-attributable summaries (one per template step) plus the document-level
 * Soll/Ist findings that cannot be attributed to a single step. Never persisted.
 */
final readonly class TemplateGraphFindings
{
    /**
     * @param array<string, StepFindingSummary> $stepSummaries keyed by step key
     */
    public function __construct(
        public array $stepSummaries,
        public int $totalDocuments,
        public int $processedDocuments,
        public bool $limitReached,
        public int $processDeviations,
        public int $processWarnings,
        public int $processTechnical
    ) {
    }

    public function summaryFor(string $stepKey): ?StepFindingSummary
    {
        return $this->stepSummaries[$stepKey] ?? null;
    }

    public function hasProcessFindings(): bool
    {
        return $this->processDeviations > 0 || $this->processWarnings > 0 || $this->processTechnical > 0;
    }
}
