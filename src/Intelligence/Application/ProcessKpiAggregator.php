<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\KpiMeasurementReason;
use App\Intelligence\Domain\ProcessRunMeasurement;
use App\Intelligence\Domain\ProcessTemplate;

final readonly class ProcessKpiAggregator
{
    public function __construct(private StepKpiAggregator $stepAggregator = new StepKpiAggregator(), private ProcessRunConformanceEvaluator $conformance = new ProcessRunConformanceEvaluator())
    {
    }

    /** @param list<ProcessRunMeasurement> $runs Measurements reconstructed as of period.until. */
    public function aggregate(ProcessTemplate $template, array $runs, KpiPeriod $period): ProcessKpiSummary
    {
        $started = $completed = $open = $ambiguous = $conformantCompleted = $deviationExcluded = $technicalMeasured = 0;
        $seconds = $reasons = $visits = $names = [];
        $buckets = $period->emptyBuckets();
        foreach ($template->steps as $step) {
            $names[$step->key] = $step->name ?? $step->key;
        }
        foreach ($runs as $run) {
            $inPeriod = $period->contains($run->endedAt);
            $isOpen = $run->eligibility->firstEventAt !== null && $run->eligibility->firstEventAt < $period->until
                && ($run->endedAt === null || $run->endedAt >= $period->until);
            if (in_array(KpiMeasurementReason::AmbiguousRun, $run->e2eDuration->reasons, true)) {
                if ($inPeriod || $isOpen || $period->contains($run->eligibility->lastEventAt)) {
                    ++$ambiguous;
                    $this->addReasons($reasons, $run);
                }
                continue; // Ambiguous segments must never masquerade as countable business runs or visits.
            }
            $started += (int) $period->contains($run->startedAt);
            $open += (int) $isOpen;
            if ($inPeriod) {
                ++$completed;
                $conformant = $this->conformance->evaluate($run, $template)->conformant;
                if (!$conformant) {
                    ++$deviationExcluded;
                } else {
                    ++$conformantCompleted;
                }
                ++$buckets[$period->bucket($run->endedAt)];
                if ($run->e2eDuration->seconds !== null) {
                    ++$technicalMeasured;
                }
                if ($conformant && $run->e2eDuration->seconds !== null) {
                    $seconds[] = $run->e2eDuration->seconds;
                } else {
                    $this->addReasons($reasons, $run);
                }
            }
            if (!$this->conformance->evaluate($run, $template)->conformant) {
                continue;
            }
            foreach ($run->stepVisits as $visit) {
                $names[$visit->stepKey] ??= $visit->stepKey;
                $visits[$visit->stepKey][] = $visit;
            }
        }
        $steps = [];
        foreach ($names as $key => $name) {
            $steps[] = $this->stepAggregator->aggregate($key, $name, $visits[$key] ?? [], $period);
        }
        ksort($reasons);

        return new ProcessKpiSummary($started, $completed, $open, $completed - $technicalMeasured, $ambiguous,
            array_sum(array_column($steps, 'withoutDuration')), array_sum(array_column($steps, 'ambiguousFragments')),
            KpiStatistics::fromSeconds($seconds), $buckets, $steps, $reasons, $conformantCompleted, $deviationExcluded);
    }

    /** @param array<string, int> $reasons */
    private function addReasons(array &$reasons, ProcessRunMeasurement $run): void
    {
        foreach ($run->e2eDuration->reasons as $reason) {
            $reasons[$reason->value] = ($reasons[$reason->value] ?? 0) + 1;
        }
    }
}
