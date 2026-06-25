<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessTemplate;

/**
 * Read model for the per-document detail page.
 *
 * Groups stored timeline events by their business stepKey - before/after event
 * phases are rendered as control phases WITHIN a step group, never as separate
 * process steps. Stored visibility check results are grouped by
 * stepKey + eventPhase + checkKey. All grouping happens here, not in Twig.
 */
final readonly class DocumentDetailView
{
    /**
     * @param array<int, array{processKey: string, documentVersion: int, currentStepKey: string, status: string, id: ?int}> $instances
     * @param array<int, array{stepKey: string, eventCount: int, phases: array{before: array<int, array<string, mixed>>, after: array<int, array<string, mixed>>, unknown: array<int, array<string, mixed>>}}> $steps
     * @param array<int, array{stepKey: string, eventPhase: string, checkKey: string, records: array<int, VisibilityCheckResultRecord>}> $visibilityGroups
     */
    public function __construct(
        public string $processKey,
        public string $version,
        public string $documentUuid,
        public array $instances,
        public array $steps,
        public int $eventCount,
        public bool $hasTimeline,
        public array $visibilityGroups,
        public int $visibilityResultCount,
        public bool $hasVisibilityResults
    ) {
    }

    /**
     * @param array<int, VisibilityCheckResultRecord> $visibilityRecords
     */
    public static function fromData(
        ProcessTemplate $template,
        string $documentUuid,
        DocumentTimelineReport $timeline,
        array $visibilityRecords
    ): self {
        $processKey = $template->key;

        $instances = array_values(array_map(
            static fn (DocumentTimelineInstanceRow $row): array => [
                'processKey' => $row->processKey,
                'documentVersion' => $row->documentVersion,
                'currentStepKey' => $row->currentStepKey,
                'status' => $row->status,
                'id' => $row->id,
            ],
            array_filter(
                $timeline->instances,
                static fn (DocumentTimelineInstanceRow $row): bool => $row->processKey === $processKey
            )
        ));

        $events = array_values(array_filter(
            $timeline->events,
            static fn (DocumentTimelineEventRow $row): bool => $row->processKey === $processKey
        ));

        $stepOrder = [];
        $byStep = [];
        foreach ($events as $event) {
            $stepKey = $event->stepKey;
            if (!array_key_exists($stepKey, $byStep)) {
                $byStep[$stepKey] = ['before' => [], 'after' => [], 'unknown' => []];
                $stepOrder[] = $stepKey;
            }
            $phase = in_array($event->eventPhase, ['before', 'after'], true) ? $event->eventPhase : 'unknown';
            $byStep[$stepKey][$phase][] = self::eventRow($event);
        }

        $steps = [];
        foreach ($stepOrder as $stepKey) {
            $phases = $byStep[$stepKey];
            $steps[] = [
                'stepKey' => $stepKey,
                'eventCount' => count($phases['before']) + count($phases['after']) + count($phases['unknown']),
                'phases' => $phases,
            ];
        }

        $visibilityOrder = [];
        $visibilityMap = [];
        foreach ($visibilityRecords as $record) {
            $groupKey = $record->stepKey.'|'.$record->eventPhase.'|'.$record->checkKey;
            if (!array_key_exists($groupKey, $visibilityMap)) {
                $visibilityMap[$groupKey] = [
                    'stepKey' => $record->stepKey,
                    'eventPhase' => $record->eventPhase,
                    'checkKey' => $record->checkKey,
                    'records' => [],
                ];
                $visibilityOrder[] = $groupKey;
            }
            $visibilityMap[$groupKey]['records'][] = $record;
        }
        $visibilityGroups = array_map(static fn (string $key): array => $visibilityMap[$key], $visibilityOrder);

        return new self(
            $processKey,
            $template->version,
            $documentUuid,
            $instances,
            $steps,
            count($events),
            $events !== [] || $instances !== [],
            $visibilityGroups,
            count($visibilityRecords),
            $visibilityRecords !== []
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function eventRow(DocumentTimelineEventRow $event): array
    {
        return [
            'eventKey' => $event->eventKey,
            'eventPhase' => $event->eventPhase,
            'occurredAt' => $event->occurredAt,
            'receivedAt' => $event->receivedAt,
            'externalEventKey' => $event->externalEventKey,
            'processEventId' => $event->id,
            'processInstanceId' => $event->processInstanceId,
            'documentVersion' => $event->documentVersion,
            'duplicate' => $event->duplicate,
        ];
    }
}
