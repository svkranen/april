<?php

namespace App\Intelligence\Application;

final readonly class ContextHistoryReport
{
    /**
     * @param array<int, ContextHistoryEntry> $entries
     */
    public function __construct(
        public string $documentUuid,
        public string $processKey,
        public array $entries
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'documentUuid' => $this->documentUuid,
            'processKey' => $this->processKey,
            'entries' => array_map(
                static fn (ContextHistoryEntry $entry): array => $entry->toArray(),
                $this->entries
            ),
        ];
    }
}
