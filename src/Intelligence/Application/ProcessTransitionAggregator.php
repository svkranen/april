<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\KpiMeasurementReason;
use App\Intelligence\Domain\ProcessRunMeasurement;

final readonly class ProcessTransitionAggregator
{
    /** @param list<ProcessRunMeasurement> $runs @return list<ProcessTransitionAggregate> */
    public function aggregate(array $runs): array
    {
        /** @var array<string, array{from: string, to: string, items: array<string, true>, visits: int}> $aggregates */
        $aggregates = [];
        foreach ($runs as $run) {
            if ($this->mustExclude($run)) {
                continue;
            }
            $itemTransitions = [];
            foreach ($run->observedTransitions as $transition) {
                $key = $transition->fromStep."\0".$transition->toStep;
                $aggregates[$key] ??= ['from' => $transition->fromStep, 'to' => $transition->toStep, 'items' => [], 'visits' => 0];
                ++$aggregates[$key]['visits'];
                $itemTransitions[$key] = true;
            }
            foreach (array_keys($itemTransitions) as $key) {
                $aggregates[$key]['items'][$run->key] = true;
            }
        }

        $result = array_map(
            static fn (array $aggregate): ProcessTransitionAggregate => new ProcessTransitionAggregate(
                $aggregate['from'], $aggregate['to'], count($aggregate['items']), $aggregate['visits'], $aggregate['items']
            ),
            $aggregates
        );
        usort($result, static fn (ProcessTransitionAggregate $left, ProcessTransitionAggregate $right): int =>
            [$left->fromStep, $left->toStep] <=> [$right->fromStep, $right->toStep]
        );

        return $result;
    }

    private function mustExclude(ProcessRunMeasurement $run): bool
    {
        // A version-boundary crossing can make E2E ineligible, but does not
        // invalidate an otherwise deterministic observed step sequence.
        return in_array(KpiMeasurementReason::AmbiguousRun, $run->e2eDuration->reasons, true);
    }
}
