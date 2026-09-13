<?php

namespace App\Intelligence\Application;

final readonly class ProcessKpiSummary
{
    /**
     * @param array<string, int> $completions
     * @param list<StepKpiSummary> $steps
     * @param array<string, int> $reasons
     */
    public function __construct(
        public int $started,
        public int $completed,
        public int $open,
        public int $completedWithoutDuration,
        public int $ambiguousRuns,
        public int $stepVisitsWithoutDuration,
        public int $ambiguousStepFragments,
        public KpiStatistics $e2e,
        public array $completions,
        public array $steps,
        public array $reasons,
        public int $conformantCompleted = 0,
        public int $deviationExcluded = 0
    ) {
    }
    public function isEmpty(): bool
    {
        return $this->started === 0 && $this->completed === 0 && $this->open === 0 && $this->ambiguousRuns === 0;
    }

}
