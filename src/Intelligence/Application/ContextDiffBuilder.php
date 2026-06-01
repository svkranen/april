<?php

namespace App\Intelligence\Application;

final class ContextDiffBuilder
{
    private const MISSING = '__april_missing_context_field__';

    public function build(ContextHistoryReport $history): ContextDiffReport
    {
        $fields = [];
        foreach ($history->entries as $entry) {
            foreach (array_keys($entry->contextJson) as $field) {
                $fields[$field] = true;
            }
        }

        $changedFields = [];
        $addedFields = [];
        $removedFields = [];
        $unchangedFields = [];
        $fieldHistory = [];

        foreach (array_keys($fields) as $field) {
            $previous = self::MISSING;
            $previousComparable = self::MISSING;
            $hadChange = false;
            $presentValues = [];

            foreach ($history->entries as $entryIndex => $entry) {
                $exists = array_key_exists($field, $entry->contextJson);
                $value = $exists ? $entry->contextJson[$field] : self::MISSING;
                $comparable = $exists ? $this->comparableValue($value) : self::MISSING;
                $fieldHistory[$field][] = [
                    'at' => $entry->at->format(DATE_ATOM),
                    'exists' => $exists,
                    'value' => $exists ? $value : null,
                ];

                if ($exists) {
                    $presentValues[] = $value;
                }

                if ($previousComparable === self::MISSING && $comparable !== self::MISSING && $entryIndex > 0) {
                    $addedFields[$field][] = [
                        'at' => $entry->at->format(DATE_ATOM),
                        'value' => $value,
                    ];
                    $hadChange = true;
                } elseif ($previousComparable !== self::MISSING && $comparable === self::MISSING) {
                    $removedFields[$field][] = [
                        'at' => $entry->at->format(DATE_ATOM),
                        'value' => $previous,
                    ];
                    $hadChange = true;
                } elseif ($previousComparable !== self::MISSING && $comparable !== self::MISSING && $previousComparable !== $comparable) {
                    $changedFields[$field][] = [
                        'at' => $entry->at->format(DATE_ATOM),
                        'from' => $previous,
                        'to' => $value,
                    ];
                    $hadChange = true;
                }

                $previous = $value;
                $previousComparable = $comparable;
            }

            if (!$hadChange && $presentValues !== []) {
                $unchangedFields[$field] = $presentValues[0];
            }
        }

        ksort($changedFields);
        ksort($addedFields);
        ksort($removedFields);
        ksort($unchangedFields);
        ksort($fieldHistory);

        return new ContextDiffReport($changedFields, $addedFields, $removedFields, $unchangedFields, $fieldHistory);
    }

    private function comparableValue(mixed $value): string
    {
        return json_encode($this->normalizeValue($value), JSON_THROW_ON_ERROR);
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = $this->normalizeValue($item);
        }

        if (!array_is_list($normalized)) {
            ksort($normalized);
        }

        return $normalized;
    }
}
