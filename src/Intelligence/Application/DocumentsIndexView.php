<?php

namespace App\Intelligence\Application;

/**
 * Read model for the per-template document list incl. the local (in-memory)
 * severity filter. Encapsulates normalisation, the row+finding+category join and
 * the filtering so Twig stays free of logic.
 *
 * Category semantics (per row):
 *   - findings computed       -> the finding's overall severity (critical/deviation/warning/technical/ok)
 *   - computed but errored    -> "technical" (carried by DocumentListFindingView::failed)
 *   - beyond the limit / off  -> "not_calculated"
 */
final readonly class DocumentsIndexView
{
    /**
     * @param array<int, array{row: DocumentListRow, finding: ?DocumentListFindingView, category: string}> $entries
     * @param array<string, string> $severityOptions
     */
    public function __construct(
        public bool $withFindings,
        public string $severity,
        public string $activeLabel,
        public int $totalCount,
        public int $shownCount,
        public int $findingsLimit,
        public bool $limitReached,
        public array $entries,
        public array $severityOptions,
        public ?string $stepKey = null,
        public ?string $stepLabel = null
    ) {
    }

    /**
     * @param array<int, DocumentListRow> $rows
     * @param array<string, DocumentListFindingView> $findings keyed by document UUID
     * @param ?string $stepKey when set (and findings are computed) the list is additionally
     *                         restricted to documents with a step-attributable finding for that step
     */
    public static function build(
        array $rows,
        bool $withFindings,
        array $findings,
        ?string $rawSeverity,
        int $findingsLimit,
        ?string $stepKey = null,
        ?string $stepLabel = null
    ): self {
        $severity = FindingSeverityFilter::normalize($rawSeverity);
        $limitReached = $withFindings && count($rows) > $findingsLimit;

        $allEntries = [];
        foreach ($rows as $row) {
            $finding = $withFindings ? ($findings[$row->documentUuid] ?? null) : null;
            $category = $finding !== null ? $finding->severity : FindingSeverityFilter::NOT_CALCULATED;
            $allEntries[] = ['row' => $row, 'finding' => $finding, 'category' => $category];
        }

        $entries = $allEntries;

        // Filtering only applies once findings are computed and a specific filter is chosen.
        if ($withFindings && $severity !== FindingSeverityFilter::ALL) {
            $entries = array_values(array_filter(
                $entries,
                static fn (array $entry): bool => $entry['category'] === $severity
            ));
        }

        // Step filter acts only on findings that actually carry the requested step;
        // process-wide findings (no stepKey) are never matched here.
        if ($withFindings && $stepKey !== null) {
            $entries = array_values(array_filter(
                $entries,
                static fn (array $entry): bool => $entry['finding'] !== null && $entry['finding']->hasStep($stepKey)
            ));
        }

        return new self(
            $withFindings,
            $severity,
            FindingSeverityFilter::label($severity),
            count($rows),
            count($entries),
            $findingsLimit,
            $limitReached,
            $entries,
            FindingSeverityFilter::OPTIONS,
            $withFindings ? $stepKey : null,
            $withFindings ? $stepLabel : null
        );
    }
}
