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
     * @param array<string, string> $gatewayStatusByNodeId decision gateway node id => worst FindingSeverityFilter status, for node colouring
     * @param array<int, AttributedFinding> $attributedFindings transition/decision findings attributed to a gateway or edge, for the dedicated section
     */
    public function __construct(
        public array $stepSummaries,
        public int $totalDocuments,
        public int $processedDocuments,
        public bool $limitReached,
        public int $processDeviations,
        public int $processWarnings,
        public int $processTechnical,
        public array $gatewayStatusByNodeId = [],
        public array $attributedFindings = []
    ) {
    }

    public function summaryFor(string $stepKey): ?StepFindingSummary
    {
        return $this->stepSummaries[$stepKey] ?? null;
    }

    /**
     * Worst severity attributed to a decision gateway node, or null if none.
     * Used by the graph builder to colour the gateway instead of leaving it neutral.
     */
    public function gatewayStatusFor(string $nodeId): ?string
    {
        return $this->gatewayStatusByNodeId[$nodeId] ?? null;
    }

    public function hasAttributedFindings(): bool
    {
        return $this->attributedFindings !== [];
    }

    /**
     * True when there are deviations/warnings that could NOT be attributed to a
     * step, gateway or edge and therefore remain process-wide.
     */
    public function hasProcessFindings(): bool
    {
        return $this->processDeviations > 0 || $this->processWarnings > 0 || $this->processTechnical > 0;
    }
}
