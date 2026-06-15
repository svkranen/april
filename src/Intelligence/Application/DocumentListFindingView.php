<?php

namespace App\Intelligence\Application;

/**
 * Compact per-row findings summary for the document list. A trimmed projection
 * of DocumentFindingsView - only what a list row needs (overall status + counts).
 */
final readonly class DocumentListFindingView
{
    /**
     * @param array<string, int> $countsByCategory
     */
    public function __construct(
        public string $documentUuid,
        public string $severity,
        public string $label,
        public string $cssClass,
        public int $total,
        public array $countsByCategory,
        public ?string $error
    ) {
    }

    public static function fromFindings(string $documentUuid, DocumentFindingsView $findings): self
    {
        return new self(
            $documentUuid,
            $findings->overallSeverity,
            $findings->overallLabel,
            $findings->overallCssClass,
            $findings->total,
            $findings->countsByCategory,
            null
        );
    }

    public static function failed(string $documentUuid, string $error): self
    {
        return new self(
            $documentUuid,
            DocumentFindingsView::SEVERITY_TECHNICAL,
            'Technisch',
            'vs-unknown',
            0,
            ['process' => 0, 'context' => 0, 'access' => 0, 'technical' => 0],
            $error
        );
    }
}
