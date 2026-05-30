<?php

namespace App\Intelligence\Application;

final class DecisionPointFieldAnalyzer
{
    /**
     * @param array<int, array{
     *     document_uuid?: string,
     *     after_step_key: string,
     *     next_step_key: string,
     *     context?: array<string, mixed>|null
     * }> $observedTransitions
     * @return array<int, DecisionPointFieldEvidence>
     */
    public function analyze(DecisionPointCandidate $candidate, array $observedTransitions): array
    {
        $transitions = $this->matchingTransitions($candidate, $observedTransitions);
        $transitionCount = count($transitions);
        if ($transitionCount === 0) {
            return [];
        }

        $fieldStats = [];
        foreach ($transitions as $transition) {
            $nextStepKey = $transition['next_step_key'];
            $context = $transition['context'] ?? null;
            if (!is_array($context)) {
                continue;
            }

            foreach ($context as $fieldKey => $value) {
                if (!is_string($fieldKey) || $fieldKey === '' || $value === null) {
                    continue;
                }

                $fieldStats[$fieldKey] ??= [
                    'present_count' => 0,
                    'values_by_next_step' => [],
                    'distinct_values' => [],
                ];
                ++$fieldStats[$fieldKey]['present_count'];
                $fieldStats[$fieldKey]['values_by_next_step'][$nextStepKey] ??= [];

                $valueKey = $this->valueKey($value);
                $fieldStats[$fieldKey]['values_by_next_step'][$nextStepKey][$valueKey] ??= $value;
                $fieldStats[$fieldKey]['distinct_values'][$valueKey] ??= $value;
            }
        }

        $evidence = [];
        foreach ($fieldStats as $fieldKey => $stats) {
            $distinctValueCount = count($stats['distinct_values']);
            if ($distinctValueCount < 2) {
                continue;
            }

            $observedValuesByNextStep = [];
            foreach ($candidate->observedNextSteps as $nextStepKey) {
                $observedValuesByNextStep[$nextStepKey] = array_values($stats['values_by_next_step'][$nextStepKey] ?? []);
            }

            $coverage = round($stats['present_count'] / $transitionCount, 4);
            $evidence[] = new DecisionPointFieldEvidence(
                $fieldKey,
                $observedValuesByNextStep,
                $coverage,
                $distinctValueCount,
                sprintf(
                    'Observed %d distinct values across %d transition(s); coverage %.4f.',
                    $distinctValueCount,
                    $transitionCount,
                    $coverage
                )
            );
        }

        usort(
            $evidence,
            static fn (DecisionPointFieldEvidence $left, DecisionPointFieldEvidence $right): int => $right->coverage <=> $left->coverage
                ?: $right->distinctValueCount <=> $left->distinctValueCount
                ?: $left->fieldKey <=> $right->fieldKey
        );

        return $evidence;
    }

    /**
     * @param array<int, array{
     *     document_uuid?: string,
     *     after_step_key: string,
     *     next_step_key: string,
     *     context?: array<string, mixed>|null
     * }> $observedTransitions
     * @return array<int, array{next_step_key: string, context?: array<string, mixed>|null}>
     */
    private function matchingTransitions(DecisionPointCandidate $candidate, array $observedTransitions): array
    {
        $documentLookup = array_fill_keys($candidate->documentUuids, true);
        $nextStepLookup = array_fill_keys($candidate->observedNextSteps, true);
        $matches = [];

        foreach ($observedTransitions as $transition) {
            if (($transition['after_step_key'] ?? null) !== $candidate->afterStepKey) {
                continue;
            }

            $nextStepKey = $transition['next_step_key'] ?? null;
            if (!is_string($nextStepKey) || !isset($nextStepLookup[$nextStepKey])) {
                continue;
            }

            $documentUuid = $transition['document_uuid'] ?? null;
            if (is_string($documentUuid) && $documentLookup !== [] && !isset($documentLookup[$documentUuid])) {
                continue;
            }

            $matches[] = [
                'next_step_key' => $nextStepKey,
                'context' => $transition['context'] ?? null,
            ];
        }

        return $matches;
    }

    private function valueKey(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return get_debug_type($value).':'.(string) $value;
        }

        return get_debug_type($value).':'.json_encode($value);
    }
}
