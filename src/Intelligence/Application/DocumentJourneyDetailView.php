<?php

namespace App\Intelligence\Application;

final readonly class DocumentJourneyDetailView
{
    /**
     * @param array<int, array{id: ?int, processKey: string, documentVersion: int, currentStepKey: string, status: string, knownTemplate: bool}> $instances
     * @param array<int, DocumentJourneyEventView> $events
     * @param array<int, DocumentJourneyProcessGroupView> $processGroups
     * @param array<string, bool> $knownTemplatesByProcessKey
     */
    public function __construct(
        public string $documentUuid,
        public ?int $documentVersion,
        public bool $hasTimeline,
        public array $instances,
        public array $events,
        public array $processGroups,
        public array $knownTemplatesByProcessKey
    ) {
    }

    /**
     * @param array<string, bool> $knownTemplatesByProcessKey
     */
    public static function fromTimeline(
        DocumentTimelineReport $timeline,
        ?int $documentVersion,
        array $knownTemplatesByProcessKey
    ): self {
        $instances = array_values(array_filter(
            $timeline->instances,
            static fn (DocumentTimelineInstanceRow $row): bool => $documentVersion === null || $row->documentVersion === $documentVersion
        ));
        $events = array_values(array_filter(
            $timeline->events,
            static fn (DocumentTimelineEventRow $row): bool => $documentVersion === null || $row->documentVersion === $documentVersion
        ));

        return new self(
            $timeline->documentUuid,
            $documentVersion,
            $instances !== [] || $events !== [],
            array_map(
                static fn (DocumentTimelineInstanceRow $row): array => [
                    'id' => $row->id,
                    'processKey' => $row->processKey,
                    'documentVersion' => $row->documentVersion,
                    'currentStepKey' => $row->currentStepKey,
                    'status' => $row->status,
                    'knownTemplate' => $knownTemplatesByProcessKey[$row->processKey] ?? false,
                ],
                $instances
            ),
            array_map(
                static fn (DocumentTimelineEventRow $row): DocumentJourneyEventView => DocumentJourneyEventView::fromTimelineRow($row),
                $events
            ),
            self::processGroups($events, $instances, $knownTemplatesByProcessKey),
            $knownTemplatesByProcessKey
        );
    }

    /**
     * @param array<int, DocumentTimelineEventRow> $events
     * @param array<int, DocumentTimelineInstanceRow> $instances
     * @param array<string, bool> $knownTemplatesByProcessKey
     * @return array<int, DocumentJourneyProcessGroupView>
     */
    private static function processGroups(array $events, array $instances, array $knownTemplatesByProcessKey): array
    {
        $groups = [];

        foreach ($events as $event) {
            $groups[$event->processKey] ??= [
                'eventCount' => 0,
                'instanceCount' => 0,
                'versions' => [],
                'first' => null,
                'last' => null,
            ];

            ++$groups[$event->processKey]['eventCount'];
            $groups[$event->processKey]['versions'][$event->documentVersion] = true;
            $first = $groups[$event->processKey]['first'];
            $last = $groups[$event->processKey]['last'];
            $groups[$event->processKey]['first'] = $first instanceof \DateTimeImmutable && $first <= $event->occurredAt ? $first : $event->occurredAt;
            $groups[$event->processKey]['last'] = $last instanceof \DateTimeImmutable && $last >= $event->occurredAt ? $last : $event->occurredAt;
        }

        foreach ($instances as $instance) {
            $groups[$instance->processKey] ??= [
                'eventCount' => 0,
                'instanceCount' => 0,
                'versions' => [],
                'first' => null,
                'last' => null,
            ];
            ++$groups[$instance->processKey]['instanceCount'];
            $groups[$instance->processKey]['versions'][$instance->documentVersion] = true;
        }

        ksort($groups);

        $result = [];
        foreach ($groups as $processKey => $group) {
            $versions = array_map('intval', array_keys($group['versions']));
            sort($versions);
            $result[] = new DocumentJourneyProcessGroupView(
                $processKey,
                $knownTemplatesByProcessKey[$processKey] ?? false,
                $group['eventCount'],
                $group['instanceCount'],
                $versions,
                $group['first'],
                $group['last']
            );
        }

        return $result;
    }
}
