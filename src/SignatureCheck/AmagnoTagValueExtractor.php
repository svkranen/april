<?php

namespace App\SignatureCheck;

final class AmagnoTagValueExtractor
{
    /**
     * @param array<string, mixed> $tagPayload
     * @param callable(string): array<string, mixed> $selectionResolver
     * @return array<int, string>
     */
    public function extractValues(array $tagPayload, string $tagDefinitionId, callable $selectionResolver): array
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

                if ($groupType === 'selections') {
                    foreach (($tag['selectedNodeIds'] ?? []) as $nodeId) {
                        if (!is_string($nodeId) || $nodeId === '') {
                            continue;
                        }

                        $node = $selectionResolver($nodeId);
                        $value = $this->normalizeValue($node['value'] ?? null);
                        if ($value !== null) {
                            $values[] = $value;
                        }
                    }

                    continue;
                }

                $value = $this->normalizeValue($tag['value'] ?? null);
                if ($value !== null) {
                    $values[] = $value;
                }
            }
        }

        return $values;
    }

    private function normalizeValue(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $stringValue = trim((string) $value);

        return $stringValue === '' ? null : $stringValue;
    }
}
