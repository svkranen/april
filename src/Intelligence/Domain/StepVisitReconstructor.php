<?php

namespace App\Intelligence\Domain;

final class StepVisitReconstructor
{
    /**
     * @param list<ProcessEventRecord> $events Sorted by occurredAt.
     * @param list<KpiMeasurementReason> $runReasons
     * @return list<StepVisitMeasurement>
     */
    public function reconstruct(array $events, array $runReasons = []): array
    {
        $steps = [];
        foreach ($events as $event) {
            $steps[$event->stepKey][] = $event;
        }
        $visits = [];
        foreach ($steps as $stepEvents) {
            $number = 0;
            foreach ($this->clusters($stepEvents) as $cluster) {
                $before = array_values(array_filter($cluster, static fn (ProcessEventRecord $e): bool => $e->eventPhase === 'before'));
                $after = array_values(array_filter($cluster, static fn (ProcessEventRecord $e): bool => $e->eventPhase === 'after'));
                $unknown = count($before) + count($after) !== count($cluster);
                $ambiguous = count($before) > 1 || count($after) > 1 || $unknown;
                $reasons = $runReasons;
                if ($before === []) {
                    $reasons[] = KpiMeasurementReason::MissingBefore;
                }
                if ($after === []) {
                    $reasons[] = KpiMeasurementReason::MissingAfter;
                }
                if ($ambiguous) {
                    $reasons[] = $unknown ? KpiMeasurementReason::UnknownPhase : KpiMeasurementReason::AmbiguousVisit;
                }
                $beforeAt = count($before) === 1 ? $before[0]->occurredAt : null;
                $afterAt = count($after) === 1 ? $after[0]->occurredAt : null;
                $visits[] = new StepVisitMeasurement(
                    $cluster[0]->stepKey,
                    $ambiguous ? null : ++$number,
                    $beforeAt,
                    $afterAt,
                    $ambiguous ? 'ambiguous' : ($afterAt === null ? 'open' : 'completed'),
                    KpiDuration::between($beforeAt, $afterAt, $reasons),
                    array_map(static fn (ProcessEventRecord $e): string => $e->externalEventKey, $cluster),
                    ['before' => $before !== [], 'after' => $after !== []],
                    min(array_map(static fn (ProcessEventRecord $e) => $e->occurredAt, $cluster)),
                    max(array_map(static fn (ProcessEventRecord $e) => $e->occurredAt, $cluster))
                );
            }
        }

        return $visits;
    }

    /** @param list<ProcessEventRecord> $events @return list<list<ProcessEventRecord>> */
    private function clusters(array $events): array
    {
        // Equal-time multiple candidates cannot be ordered using receipt time or IDs.
        $times = [];
        foreach ($events as $event) {
            $time = $event->occurredAt->format('U.u');
            $times[$time][$event->eventPhase] = ($times[$time][$event->eventPhase] ?? 0) + 1;
            if ($times[$time][$event->eventPhase] > 1 || !in_array($event->eventPhase, ['before', 'after'], true)) {
                return [$events];
            }
        }
        usort($events, static fn (ProcessEventRecord $a, ProcessEventRecord $b): int =>
            ($a->occurredAt <=> $b->occurredAt) ?: (($a->eventPhase === 'before' ? 0 : 1) <=> ($b->eventPhase === 'before' ? 0 : 1)));
        $clusters = [];
        $cluster = [];
        $hasAfter = false;
        foreach ($events as $event) {
            if ($event->eventPhase === 'before' && $hasAfter) {
                $clusters[] = $cluster;
                $cluster = [];
                $hasAfter = false;
            }
            $cluster[] = $event;
            $hasAfter = $hasAfter || $event->eventPhase === 'after';
        }
        if ($cluster !== []) {
            $clusters[] = $cluster;
        }

        foreach ($clusters as $candidate) {
            $beforeCount = count(array_filter($candidate, static fn (ProcessEventRecord $e): bool => $e->eventPhase === 'before'));
            $afterCount = count($candidate) - $beforeCount;
            if ($beforeCount > 1 || $afterCount > 1) {
                // An unmatched candidate could belong to a later pair. Do not guess where ambiguity ends.
                return [$events];
            }
        }

        return $clusters;
    }
}
