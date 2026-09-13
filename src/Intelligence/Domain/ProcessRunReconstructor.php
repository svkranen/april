<?php

namespace App\Intelligence\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;

final readonly class ProcessRunReconstructor
{
    public function __construct(
        private TimelineKpiEligibilityResolver $eligibilityResolver = new TimelineKpiEligibilityResolver(),
        private StepVisitReconstructor $visitReconstructor = new StepVisitReconstructor()
    ) {
    }

    /**
     * Complete stored history is required; do not truncate to a reporting period.
     * @param iterable<ProcessEventRecord> $events
     * @param list<ProcessVersion> $versions All process version boundaries, before any selection.
     * @return list<ProcessRunMeasurement>
     */
    public function reconstruct(ProcessTemplate $template, ProcessKpiDefinition $definition, iterable $events, array $versions): array
    {
        if ($template->key !== $definition->templateKey || $template->version !== $definition->templateVersion) {
            throw new InvalidArgumentException('The measurement definition belongs to a different template revision.');
        }
        $items = [];
        $seen = [];
        foreach ($events as $event) {
            if ($event->processKey !== $template->key) {
                continue;
            }
            if (isset($seen[$event->externalEventKey])) {
                continue; // The event store defines this key as globally unique and idempotent.
            }
            $seen[$event->externalEventKey] = true;
            $identity = [$event->sourceSystem, $event->documentUuid !== null && $event->documentUuid !== ''
                ? ['uuid', $event->documentUuid] : ['external', $event->documentExternalId]];
            $items[json_encode($identity, JSON_THROW_ON_ERROR)][] = $event;
        }
        ksort($items);
        $runs = [];
        foreach ($items as $identity => $itemEvents) {
            usort($itemEvents, static fn (ProcessEventRecord $a, ProcessEventRecord $b): int =>
                ($a->occurredAt <=> $b->occurredAt) ?: ($a->externalEventKey <=> $b->externalEventKey));
            foreach ($this->segments($itemEvents, $definition) as $segment) {
                $runs[] = $this->measure($identity, $segment, $template, $definition, $versions);
            }
        }

        return $runs;
    }

    /** @param list<ProcessEventRecord> $events @return list<list<ProcessEventRecord>> */
    private function segments(array $events, ProcessKpiDefinition $definition): array
    {
        $segments = [];
        $segment = [];
        $batches = [];
        foreach ($events as $event) {
            $batches[$event->occurredAt->format('U.u')][] = $event;
        }
        $observed = [];
        foreach ($batches as $batch) {
            $end = $this->completionTime($observed, $definition);
            $starts = array_filter($batch, $definition->start->matches(...));
            // A simultaneous batch stays together; technical tie breakers cannot assign a business run.
            if ($starts !== [] && $end !== null && $batch[0]->occurredAt > $end) {
                $segments[] = $segment;
                $segment = [];
                $observed = [];
            }
            foreach ($batch as $event) {
                $observed[$event->stepKey][$event->eventPhase] ??= $event->occurredAt;
            }
            array_push($segment, ...$batch);
        }
        if ($segment !== []) {
            $segments[] = $segment;
        }

        return $segments;
    }

    /** @param list<ProcessEventRecord> $events */
    private function endTime(array $events, ProcessKpiDefinition $definition): ?DateTimeImmutable
    {
        $observed = [];
        foreach ($events as $event) {
            $observed[$event->stepKey][$event->eventPhase] ??= $event->occurredAt;
        }

        return $this->completionTime($observed, $definition);
    }

    /** @param array<string, array<string, DateTimeImmutable>> $observed */
    private function completionTime(array $observed, ProcessKpiDefinition $definition): ?DateTimeImmutable
    {
        $ends = [];
        foreach ($definition->completionGroups as $group) {
            $times = [];
            foreach ($group as $marker) {
                if (!isset($observed[$marker->stepKey][$marker->phase])) {
                    continue 2;
                }
                $times[] = $observed[$marker->stepKey][$marker->phase];
            }
            $ends[] = max($times);
        }

        return $ends === [] ? null : min($ends);
    }

    /** @param list<ProcessEventRecord> $events @param list<ProcessVersion> $versions */
    private function measure(string $identity, array $events, ProcessTemplate $template, ProcessKpiDefinition $definition, array $versions): ProcessRunMeasurement
    {
        $starts = array_values(array_filter($events, $definition->start->matches(...)));
        $start = count($starts) === 1 ? $starts[0]->occurredAt : null;
        $end = $this->endTime($events, $definition);
        $documents = [];
        $instanceIds = [];
        foreach ($events as $event) {
            $documents[$event->documentVersion] = new DocumentRef($event->sourceSystem, $event->documentExternalId, $event->documentUuid, $event->documentVersion);
            if ($event->processInstanceId !== null) {
                $instanceIds[$event->processInstanceId] = $event->processInstanceId;
            }
        }
        $eligibility = $this->eligibilityResolver->resolve(
            $template->key,
            array_map(static fn (ProcessEventRecord $e): KpiTimelineEntry => new KpiTimelineEntry($e->stepKey, $e->occurredAt), $events),
            $template->initialStepKey ?? $template->steps[0]->key ?? null,
            $versions
        );
        $sharedReasons = [];
        if (!$eligibility->isEligible) {
            $sharedReasons[] = KpiMeasurementReason::from($eligibility->exclusionReason ?? throw new LogicException('Ineligible timelines require a reason.'));
        }
        if (count($starts) > 1 || count($documents) > 1 || count($instanceIds) > 1
            || ($end !== null && $events[count($events) - 1]->occurredAt > $end)
            || ($start !== null && $events[0]->occurredAt < $start)
            || $this->hasRepeatedCompletionMarker($events, $definition)) {
            $sharedReasons[] = KpiMeasurementReason::AmbiguousRun;
        }
        $reasons = $sharedReasons;
        if ($starts === []) {
            $reasons[] = KpiMeasurementReason::MissingStart;
        }
        if ($end === null) {
            $reasons[] = KpiMeasurementReason::MissingEnd;
        }
        $keys = array_map(static fn (ProcessEventRecord $e): string => $e->externalEventKey, $events);

        return new ProcessRunMeasurement(
            hash('sha256', $identity.'|'.$template->key.'|'.$keys[0]),
            $template->key,
            $template->version,
            $definition->version,
            array_values($documents),
            array_values($instanceIds),
            $start,
            $end,
            $end === null ? 'running' : 'completed',
            $eligibility,
            KpiDuration::between($start, $end, $reasons),
            $this->visitReconstructor->reconstruct($events, $sharedReasons),
            $keys
        );
    }

    /** @param list<ProcessEventRecord> $events */
    private function hasRepeatedCompletionMarker(array $events, ProcessKpiDefinition $definition): bool
    {
        foreach ($definition->completionGroups as $group) {
            foreach ($group as $marker) {
                if (count(array_filter($events, $marker->matches(...))) > 1) {
                    return true;
                }
            }
        }

        return false;
    }
}
