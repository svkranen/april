<?php

namespace App\Intelligence\Application;

final readonly class ContextDiffReport
{
    /**
     * @param array<string, array<int, array<string, mixed>>> $changedFields
     * @param array<string, array<int, array<string, mixed>>> $addedFields
     * @param array<string, array<int, array<string, mixed>>> $removedFields
     * @param array<string, mixed> $unchangedFields
     * @param array<string, array<int, array<string, mixed>>> $fieldHistory
     */
    public function __construct(
        public array $changedFields,
        public array $addedFields,
        public array $removedFields,
        public array $unchangedFields,
        public array $fieldHistory
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'changed_fields' => $this->changedFields,
            'added_fields' => $this->addedFields,
            'removed_fields' => $this->removedFields,
            'unchanged_fields' => $this->unchangedFields,
            'field_history' => $this->fieldHistory,
        ];
    }
}
