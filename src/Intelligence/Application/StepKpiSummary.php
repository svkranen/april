<?php

namespace App\Intelligence\Application;

final readonly class StepKpiSummary
{
    /**
     * @param array{before: int, after: int, both: int, beforeOnly: int, afterOnly: int, neither: int} $coverage
     * @param array<string, int> $reasons
     */
    public function __construct(
        public string $stepKey,
        public string $name,
        public int $visits,
        public int $completed,
        public int $open,
        public int $withoutDuration,
        public int $ambiguousFragments,
        public KpiStatistics $duration,
        public array $coverage,
        public bool $mixedCoverage,
        public array $reasons
    ) {
    }
}
