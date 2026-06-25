<?php

namespace App\Intelligence\Application;

/**
 * Aggregated, step-attributable findings for one process step, used by the
 * template Mermaid graph. Severities mirror DocumentFindingsView / the existing
 * FindingSeverityFilter so the graph and the document list stay consistent.
 *
 * Only access/visibility findings carry a stepKey and are therefore aggregated
 * here. Soll/Ist process deviations are document-level and summarised separately
 * (see TemplateGraphFindings::$processDeviations etc.), never invented onto a step.
 */
final readonly class StepFindingSummary
{
    /** Worst-first; drives the derived node status. */
    private const RANK = [
        FindingSeverityFilter::CRITICAL => 0,
        FindingSeverityFilter::DEVIATION => 1,
        FindingSeverityFilter::WARNING => 2,
        FindingSeverityFilter::TECHNICAL => 3,
    ];

    /**
     * @param array<string, int> $counts severity => count (critical/deviation/warning/technical)
     */
    public function __construct(
        public string $stepKey,
        public string $status,
        public string $label,
        public int $total,
        public array $counts
    ) {
    }

    /**
     * @param array<int, string> $severities one entry per step-attributable finding
     */
    public static function fromSeverities(string $stepKey, array $severities, bool $computed): self
    {
        if (!$computed) {
            return new self($stepKey, FindingSeverityFilter::NOT_CALCULATED, FindingSeverityFilter::label(FindingSeverityFilter::NOT_CALCULATED), 0, self::emptyCounts());
        }

        $counts = self::emptyCounts();
        foreach ($severities as $severity) {
            if (array_key_exists($severity, $counts)) {
                ++$counts[$severity];
            }
        }

        $total = array_sum($counts);
        if ($total === 0) {
            return new self($stepKey, FindingSeverityFilter::OK, FindingSeverityFilter::label(FindingSeverityFilter::OK), 0, $counts);
        }

        return new self($stepKey, self::worst($counts), self::buildLabel($counts), $total, $counts);
    }

    /**
     * @return array<string, int>
     */
    private static function emptyCounts(): array
    {
        return [
            FindingSeverityFilter::CRITICAL => 0,
            FindingSeverityFilter::DEVIATION => 0,
            FindingSeverityFilter::WARNING => 0,
            FindingSeverityFilter::TECHNICAL => 0,
        ];
    }

    /**
     * @param array<string, int> $counts
     */
    private static function worst(array $counts): string
    {
        foreach (self::RANK as $severity => $rank) {
            if (($counts[$severity] ?? 0) > 0) {
                return $severity;
            }
        }

        return FindingSeverityFilter::OK;
    }

    /**
     * @param array<string, int> $counts
     */
    private static function buildLabel(array $counts): string
    {
        $parts = [];
        foreach (self::RANK as $severity => $rank) {
            $count = $counts[$severity] ?? 0;
            if ($count > 0) {
                $parts[] = $count.' '.FindingSeverityFilter::label($severity);
            }
        }

        return $parts === [] ? FindingSeverityFilter::label(FindingSeverityFilter::OK) : implode(' / ', $parts);
    }
}
