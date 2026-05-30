<?php

namespace App\Intelligence\Application;

final readonly class ContextCoverageReport
{
    /**
     * @param array<int, ContextCoverageFieldRow> $fields
     */
    public function __construct(
        public string $processKey,
        public int $snapshotCount,
        public array $fields
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'processKey' => $this->processKey,
            'snapshotCount' => $this->snapshotCount,
            'fields' => array_map(
                static fn (ContextCoverageFieldRow $field): array => $field->toArray(),
                $this->fields
            ),
        ];
    }
}
