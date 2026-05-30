<?php

namespace App\Intelligence\Infrastructure\Process;

use App\Intelligence\Application\ProcessStatusEventRow;
use App\Intelligence\Application\ProcessStatusInstanceRow;
use App\Intelligence\Application\ProcessStatusReport;
use App\Intelligence\Application\ProcessStatusReportProvider;
use App\Intelligence\Domain\ProcessEventRecord;
use App\Intelligence\Domain\ProcessInstance;

final class InMemoryProcessStatusReportProvider implements ProcessStatusReportProvider
{
    /**
     * @param array<int, ProcessInstance> $instances
     * @param array<int, ProcessEventRecord> $events
     */
    public function __construct(
        private readonly array $instances = [],
        private readonly array $events = []
    ) {
    }

    public function build(string $processKey): ProcessStatusReport
    {
        $instances = array_values(array_filter(
            $this->instances,
            static fn (ProcessInstance $instance): bool => $instance->processKey === $processKey
        ));

        $countsByStep = [];
        $openInstances = [];
        foreach ($instances as $instance) {
            $countsByStep[$instance->currentStepKey] = ($countsByStep[$instance->currentStepKey] ?? 0) + 1;
            if ($instance->endedAt === null) {
                $openInstances[] = new ProcessStatusInstanceRow(
                    $instance->id,
                    $instance->documentUuid,
                    $instance->documentVersion,
                    $instance->currentStepKey,
                    $instance->lastEventAt,
                    $instance->status
                );
            }
        }
        ksort($countsByStep);

        usort(
            $openInstances,
            static fn (ProcessStatusInstanceRow $left, ProcessStatusInstanceRow $right): int => $right->lastEventAt <=> $left->lastEventAt
        );

        $events = array_values(array_filter(
            $this->events,
            static fn (ProcessEventRecord $event): bool => $event->processKey === $processKey
        ));
        usort($events, static fn (ProcessEventRecord $left, ProcessEventRecord $right): int => $right->occurredAt <=> $left->occurredAt);

        $latestEvents = array_map(
            static fn (ProcessEventRecord $event): ProcessStatusEventRow => new ProcessStatusEventRow(
                $event->externalEventKey,
                $event->documentUuid,
                $event->documentVersion,
                $event->stepKey,
                $event->occurredAt
            ),
            array_slice($events, 0, 10)
        );

        return new ProcessStatusReport($processKey, count($instances), $countsByStep, $openInstances, $latestEvents);
    }
}
