<?php

namespace App\Intelligence\Application;

final class DocumentTimelineContextDiffBuilder
{
    /**
     * @param array<int, DocumentTimelineEventRow> $events
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function build(array $events): array
    {
        $previousContext = null;
        $diffsByExternalEventKey = [];

        foreach ($events as $event) {
            $context = $this->contextAttributes($event);
            if ($context === null) {
                continue;
            }

            $diffsByExternalEventKey[$event->externalEventKey] = $previousContext === null
                ? []
                : $this->diff($previousContext, $context);
            $previousContext = $context;
        }

        return $diffsByExternalEventKey;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function contextAttributes(DocumentTimelineEventRow $event): ?array
    {
        $attributes = $event->contextSummary['attributes'] ?? null;

        return is_array($attributes) ? $attributes : null;
    }

    /**
     * @param array<string, mixed> $previous
     * @param array<string, mixed> $current
     * @return array<int, array<string, mixed>>
     */
    private function diff(array $previous, array $current): array
    {
        $fields = array_fill_keys(array_merge(array_keys($previous), array_keys($current)), true);
        $diffs = [];

        foreach (array_keys($fields) as $field) {
            $hadPrevious = array_key_exists($field, $previous);
            $hasCurrent = array_key_exists($field, $current);

            if (!$hadPrevious && $hasCurrent) {
                $diffs[] = [
                    'field' => $field,
                    'type' => 'added',
                    'from' => null,
                    'to' => $current[$field],
                ];
                continue;
            }

            if ($hadPrevious && !$hasCurrent) {
                $diffs[] = [
                    'field' => $field,
                    'type' => 'removed',
                    'from' => $previous[$field],
                    'to' => null,
                ];
                continue;
            }

            if ($hadPrevious && $hasCurrent && $this->comparableValue($previous[$field]) !== $this->comparableValue($current[$field])) {
                $diffs[] = [
                    'field' => $field,
                    'type' => 'changed',
                    'from' => $previous[$field],
                    'to' => $current[$field],
                ];
            }
        }

        usort($diffs, static fn (array $left, array $right): int => ($left['field'] ?? '') <=> ($right['field'] ?? ''));

        return $diffs;
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
