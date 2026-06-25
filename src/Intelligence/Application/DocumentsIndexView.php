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
    public const FILTER_STEP = 'step';
    public const FILTER_DECISION = 'decision';
    public const FILTER_TRANSITION = 'transition';

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
        public ?string $stepLabel = null,
        public ?string $decisionKey = null,
        public ?string $decisionLabel = null,
        public ?string $transitionFrom = null,
        public ?string $transitionTo = null,
        public ?string $transitionLabel = null
    ) {
    }

    /**
     * @param array<int, DocumentListRow> $rows
     * @param array<string, DocumentListFindingView> $findings keyed by document UUID
     * @param ?string $stepKey when set (and findings are computed) the list is additionally
     *                         restricted to documents with a step-attributable finding for that step
     *
     * The graph filters (step / decision / transition) are mutually exclusive - the
     * caller passes at most one - and each combines with the severity filter (AND).
     */
    public static function build(
        array $rows,
        bool $withFindings,
        array $findings,
        ?string $rawSeverity,
        int $findingsLimit,
        ?string $stepKey = null,
        ?string $stepLabel = null,
        ?string $decisionKey = null,
        ?string $decisionLabel = null,
        ?string $transitionFrom = null,
        ?string $transitionTo = null,
        ?string $transitionLabel = null
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

        // Graph filters act only on findings that actually carry the requested
        // step / gateway / transition; process-wide findings are never matched.
        if ($withFindings && $stepKey !== null) {
            $entries = array_values(array_filter(
                $entries,
                static fn (array $entry): bool => $entry['finding'] !== null && $entry['finding']->hasStep($stepKey)
            ));
        }

        if ($withFindings && $decisionKey !== null) {
            $entries = array_values(array_filter(
                $entries,
                static fn (array $entry): bool => $entry['finding'] !== null && $entry['finding']->hasDecision($decisionKey)
            ));
        }

        if ($withFindings && $transitionFrom !== null && $transitionTo !== null) {
            $entries = array_values(array_filter(
                $entries,
                static fn (array $entry): bool => $entry['finding'] !== null && $entry['finding']->hasTransition($transitionFrom, $transitionTo)
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
            $withFindings ? $stepLabel : null,
            $withFindings ? $decisionKey : null,
            $withFindings ? $decisionLabel : null,
            $withFindings ? $transitionFrom : null,
            $withFindings ? $transitionTo : null,
            $withFindings ? $transitionLabel : null
        );
    }

    /**
     * Query params that reproduce the active graph filter (step / decision /
     * transition), so Twig can compose URLs without branching logic.
     *
     * @return array<string, string>
     */
    public function filterParams(): array
    {
        if ($this->stepKey !== null) {
            return ['step' => $this->stepKey];
        }
        if ($this->decisionKey !== null) {
            return ['decision' => $this->decisionKey];
        }
        if ($this->transitionFrom !== null && $this->transitionTo !== null) {
            return ['transitionFrom' => $this->transitionFrom, 'transitionTo' => $this->transitionTo];
        }

        return [];
    }

    public function hasGraphFilter(): bool
    {
        return $this->graphFilterKind() !== null;
    }

    public function graphFilterKind(): ?string
    {
        if ($this->stepKey !== null) {
            return self::FILTER_STEP;
        }
        if ($this->decisionKey !== null) {
            return self::FILTER_DECISION;
        }
        if ($this->transitionFrom !== null && $this->transitionTo !== null) {
            return self::FILTER_TRANSITION;
        }

        return null;
    }

    public function graphFilterLabel(): ?string
    {
        return match ($this->graphFilterKind()) {
            self::FILTER_STEP => $this->stepLabel,
            self::FILTER_DECISION => $this->decisionLabel,
            self::FILTER_TRANSITION => $this->transitionLabel,
            default => null,
        };
    }

    /** Raw key(s) for the expert view, e.g. the step/decision key or "from → to". */
    public function graphFilterExpertValue(): ?string
    {
        return match ($this->graphFilterKind()) {
            self::FILTER_STEP => $this->stepKey,
            self::FILTER_DECISION => $this->decisionKey,
            self::FILTER_TRANSITION => $this->transitionFrom.' → '.$this->transitionTo,
            default => null,
        };
    }
}
