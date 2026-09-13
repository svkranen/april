<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\StepVisitMeasurement;

final class StepKpiAggregator
{
    /** @param list<StepVisitMeasurement> $visits */
    public function aggregate(string $stepKey, string $name, array $visits, KpiPeriod $period): StepKpiSummary
    {
        $completed = $open = $ambiguous = 0;
        $seconds = $reasons = [];
        $coverage = ['before' => 0, 'after' => 0, 'both' => 0, 'beforeOnly' => 0, 'afterOnly' => 0, 'neither' => 0];
        foreach ($visits as $visit) {
            if ($visit->visitNumber === null) {
                if ($period->contains($visit->lastObservedAt)) {
                    ++$ambiguous;
                    $this->addReasons($reasons, $visit);
                }
                continue;
            }
            $isCompleted = $period->contains($visit->afterAt);
            $isOpen = $visit->firstObservedAt < $period->until && ($visit->afterAt === null || $visit->afterAt >= $period->until);
            if (!$isCompleted && !$isOpen) {
                continue;
            }
            $completed += (int) $isCompleted;
            $open += (int) $isOpen;
            if ($isCompleted && $visit->duration->seconds !== null) {
                $seconds[] = $visit->duration->seconds;
            } else {
                $this->addReasons($reasons, $visit);
            }
            $before = $visit->measurementPointCoverage['before'];
            $after = $visit->measurementPointCoverage['after'];
            $coverage['before'] += (int) $before;
            $coverage['after'] += (int) $after;
            ++$coverage[$before ? ($after ? 'both' : 'beforeOnly') : ($after ? 'afterOnly' : 'neither')];
        }
        ksort($reasons);
        $categories = array_filter([$coverage['both'], $coverage['beforeOnly'], $coverage['afterOnly'], $coverage['neither']]);

        return new StepKpiSummary($stepKey, $name, $completed + $open, $completed, $open,
            $completed + $open - count($seconds), $ambiguous, KpiStatistics::fromSeconds($seconds), $coverage, count($categories) > 1, $reasons);
    }

    /** @param array<string, int> $reasons */
    private function addReasons(array &$reasons, StepVisitMeasurement $visit): void
    {
        foreach ($visit->duration->reasons as $reason) {
            $reasons[$reason->value] = ($reasons[$reason->value] ?? 0) + 1;
        }
    }
}
