<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ContextSnapshot;

final class ContextCoverageReportBuilder
{
    /**
     * @param array<int, ContextSnapshot> $snapshots
     */
    public function build(string $processKey, array $snapshots): ContextCoverageReport
    {
        $snapshotCount = count($snapshots);
        if ($snapshotCount === 0) {
            return new ContextCoverageReport($processKey, 0, []);
        }

        $stats = [];
        foreach ($snapshots as $snapshot) {
            foreach ($snapshot->attributes as $fieldKey => $value) {
                if (!is_string($fieldKey) || $fieldKey === '') {
                    continue;
                }

                $stats[$fieldKey] ??= [
                    'present_count' => 0,
                    'types' => [],
                    'examples' => [],
                ];

                if (!$this->isPresent($value)) {
                    continue;
                }

                ++$stats[$fieldKey]['present_count'];
                $stats[$fieldKey]['types'][$this->typeOf($value)] = true;

                $exampleKey = $this->exampleKey($value);
                if (count($stats[$fieldKey]['examples']) < 3 && !array_key_exists($exampleKey, $stats[$fieldKey]['examples'])) {
                    $stats[$fieldKey]['examples'][$exampleKey] = $value;
                }
            }
        }

        $fields = [];
        foreach ($stats as $fieldKey => $fieldStats) {
            if ($fieldStats['present_count'] === 0) {
                continue;
            }

            $observedTypes = array_keys($fieldStats['types']);
            sort($observedTypes);

            $fields[] = new ContextCoverageFieldRow(
                $fieldKey,
                round($fieldStats['present_count'] / $snapshotCount, 4),
                $fieldStats['present_count'],
                $snapshotCount - $fieldStats['present_count'],
                $observedTypes,
                array_values($fieldStats['examples'])
            );
        }

        usort(
            $fields,
            static fn (ContextCoverageFieldRow $left, ContextCoverageFieldRow $right): int => $left->fieldKey <=> $right->fieldKey
        );

        return new ContextCoverageReport($processKey, $snapshotCount, $fields);
    }

    private function isPresent(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }

    private function typeOf(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'bool',
            is_int($value) => 'int',
            is_float($value) => 'float',
            is_string($value) => 'string',
            is_array($value) => 'array',
            default => get_debug_type($value),
        };
    }

    private function exampleKey(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return get_debug_type($value).':'.(string) $value;
        }

        return get_debug_type($value).':'.json_encode($value);
    }
}
