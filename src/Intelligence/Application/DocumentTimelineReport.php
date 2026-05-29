<?php

namespace App\Intelligence\Application;

final readonly class DocumentTimelineReport
{
    /**
     * @param array<int, DocumentTimelineInstanceRow> $instances
     * @param array<int, DocumentTimelineEventRow> $events
     */
    public function __construct(
        public string $documentUuid,
        public array $instances,
        public array $events
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->instances === [] && $this->events === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'documentUuid' => $this->documentUuid,
            'instances' => array_map(
                static fn (DocumentTimelineInstanceRow $row): array => $row->toArray(),
                $this->instances
            ),
            'events' => array_map(
                static fn (DocumentTimelineEventRow $row): array => $row->toArray(),
                $this->events
            ),
        ];
    }
}
