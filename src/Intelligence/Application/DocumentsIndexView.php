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
        public array $severityOptions
    ) {
    }

    /**
     * @param array<int, DocumentListRow> $rows
     * @param array<string, DocumentListFindingView> $findings keyed by document UUID
     */
    public static function build(array $rows, bool $withFindings, array $findings, ?string $rawSeverity, int $findingsLimit): self
    {
        $severity = FindingSeverityFilter::normalize($rawSeverity);
        $limitReached = $withFindings && count($rows) > $findingsLimit;

        $allEntries = [];
        foreach ($rows as $row) {
            $finding = $withFindings ? ($findings[$row->documentUuid] ?? null) : null;
            $category = $finding !== null ? $finding->severity : FindingSeverityFilter::NOT_CALCULATED;
            $allEntries[] = ['row' => $row, 'finding' => $finding, 'category' => $category];
        }

        // Filtering only applies once findings are computed and a specific filter is chosen.
        $entries = ($withFindings && $severity !== FindingSeverityFilter::ALL)
            ? array_values(array_filter($allEntries, static fn (array $entry): bool => $entry['category'] === $severity))
            : $allEntries;

        return new self(
            $withFindings,
            $severity,
            FindingSeverityFilter::label($severity),
            count($rows),
            count($entries),
            $findingsLimit,
            $limitReached,
            $entries,
            FindingSeverityFilter::OPTIONS
        );
    }
}
