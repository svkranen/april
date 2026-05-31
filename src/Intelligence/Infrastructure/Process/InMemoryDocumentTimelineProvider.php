<?php

namespace App\Intelligence\Infrastructure\Process;

use App\Intelligence\Application\DocumentTimelineEventRow;
use App\Intelligence\Application\DocumentTimelineInstanceRow;
use App\Intelligence\Application\DocumentTimelineProvider;
use App\Intelligence\Application\DocumentTimelineReport;
use App\Intelligence\Application\EventTimelineOrder;
use App\Intelligence\Domain\ContextSnapshot;
use App\Intelligence\Domain\ProcessEventRecord;
use App\Intelligence\Domain\ProcessInstance;

final class InMemoryDocumentTimelineProvider implements DocumentTimelineProvider
{
    /**
     * @param array<int, ProcessInstance> $instances
     * @param array<int, ProcessEventRecord> $events
     * @param array<int, ContextSnapshot> $snapshots
     */
    public function __construct(
        private readonly array $instances = [],
        private readonly array $events = [],
        private readonly array $snapshots = []
    ) {
    }

    public function build(string $documentUuid, EventTimelineOrder $order = EventTimelineOrder::DEFAULT): DocumentTimelineReport
    {
        $instances = array_values(array_filter(
            $this->instances,
            static fn (ProcessInstance $instance): bool => $instance->documentUuid === $documentUuid
        ));
        usort($instances, static fn (ProcessInstance $left, ProcessInstance $right): int => [$left->documentVersion, $left->id] <=> [$right->documentVersion, $right->id]);

        $events = array_values(array_filter(
            $this->events,
            static fn (ProcessEventRecord $event): bool => $event->documentUuid === $documentUuid
        ));
        usort($events, static fn (ProcessEventRecord $left, ProcessEventRecord $right): int => $order->compareProcessEvents($left, $right));

        $snapshotsByEventKey = $this->snapshotsByEventKey($documentUuid);

        return new DocumentTimelineReport(
            $documentUuid,
            array_map(
                static fn (ProcessInstance $instance): DocumentTimelineInstanceRow => new DocumentTimelineInstanceRow(
                    $instance->id,
                    $instance->processKey,
                    $instance->documentVersion,
                    $instance->currentStepKey,
                    $instance->status
                ),
                $instances
            ),
            array_map(
                static fn (ProcessEventRecord $event): DocumentTimelineEventRow => new DocumentTimelineEventRow(
                    $event->externalEventKey,
                    $event->eventKey,
                    $event->stepKey,
                    $event->processKey,
                    $event->documentVersion,
                    $event->occurredAt,
                    $event->receivedAt,
                    $event->id,
                    $event->processInstanceId,
                    $snapshotsByEventKey[$event->externalEventKey] ?? null,
                    $event->eventPhase
                ),
                $events
            )
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function snapshotsByEventKey(string $documentUuid): array
    {
        $summaries = [];
        foreach ($this->snapshots as $snapshot) {
            if ($snapshot->document->externalUuid !== $documentUuid || $snapshot->externalEventKey === null) {
                continue;
            }

            $summaries[$snapshot->externalEventKey] = [
                'attributes' => $snapshot->attributes,
                'fields' => array_keys($snapshot->attributes),
                'warnings' => $snapshot->warnings,
                'occurred_at' => $snapshot->occurredAt?->format(DATE_ATOM),
                'loaded_at' => $snapshot->loadedAt->format(DATE_ATOM),
                'incoming_event_id' => $snapshot->incomingEventId,
                'freshness_seconds' => $snapshot->occurredAt === null
                    ? $snapshot->freshnessSeconds
                    : $snapshot->loadedAt->getTimestamp() - $snapshot->occurredAt->getTimestamp(),
                'is_fresh_for_decision_check' => $snapshot->isFreshForDecisionCheck,
                'source' => 'snapshot',
            ];
        }

        return $summaries;
    }

}
