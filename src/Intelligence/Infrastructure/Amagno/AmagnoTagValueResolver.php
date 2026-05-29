<?php

namespace App\Intelligence\Infrastructure\Amagno;

final class AmagnoTagValueResolver
{
    /**
     * @param array<string, mixed> $tagPayload
     * @param callable(string): array<string, mixed> $selectionResolver
     * @return array<int, mixed>
     */
    public function resolveValues(array $tagPayload, string $tagDefinitionId, callable $selectionResolver): array
    {
        $values = [];

        foreach ($tagPayload as $groupType => $group) {
            if (!is_array($group)) {
                continue;
            }

            foreach ($group as $tag) {
                if (!is_array($tag) || ($tag['tagDefinitionId'] ?? null) !== $tagDefinitionId) {
                    continue;
                }

                foreach ($this->extractTagValues((string) $groupType, $tag, $selectionResolver) as $value) {
                    $values[] = $value;
                }
            }
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $tag
     * @param callable(string): array<string, mixed> $selectionResolver
     * @return array<int, mixed>
     */
    private function extractTagValues(string $groupType, array $tag, callable $selectionResolver): array
    {
        if ($groupType === 'selections') {
            $values = [];
            foreach (($tag['selectedNodeIds'] ?? []) as $nodeId) {
                if (!is_string($nodeId) || $nodeId === '') {
                    continue;
                }

                $node = $selectionResolver($nodeId);
                $value = $this->normalizeScalar($node['value'] ?? null);
                if ($value !== null) {
                    $values[] = $value;
                }
            }

            return $values;
        }

        $value = $this->normalizeValue($groupType, $tag['value'] ?? null);

        return $value === null ? [] : [$value];
    }

    private function normalizeValue(string $groupType, mixed $value): mixed
    {
        $value = $this->normalizeScalar($value);
        if ($value === null) {
            return null;
        }

        if ($groupType === 'numbers' || $groupType === 'counters') {
            return is_numeric($value) ? ((float) $value) / 10000 : $value;
        }

        return $value;
    }

    private function normalizeScalar(mixed $value): mixed
    {
        if (!is_scalar($value)) {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        return $value;
    }
}
