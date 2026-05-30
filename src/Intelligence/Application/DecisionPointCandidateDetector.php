<?php

namespace App\Intelligence\Application;

final class DecisionPointCandidateDetector
{
    /**
     * @param array<int, array{document_uuid: string, steps: array<int, array{key: string, normalized?: string}|string>}> $documentSequences
     * @return array<int, DecisionPointCandidate>
     */
    public function detect(array $documentSequences): array
    {
        $documentsWithTransitions = [];
        $transitionsByAfterStep = [];

        foreach ($documentSequences as $documentSequence) {
            $documentUuid = (string) ($documentSequence['document_uuid'] ?? '');
            if ($documentUuid === '') {
                continue;
            }

            $steps = $this->normalizedSteps($documentSequence['steps'] ?? []);
            if (count($steps) < 2) {
                continue;
            }

            for ($i = 0, $max = count($steps) - 1; $i < $max; ++$i) {
                $from = $steps[$i];
                $to = $steps[$i + 1];
                if ($from['normalized'] === $to['normalized']) {
                    continue;
                }

                $documentsWithTransitions[$documentUuid] = true;
                $afterKey = $from['normalized'];
                $nextKey = $to['normalized'];
                $transitionsByAfterStep[$afterKey] ??= [
                    'after_step_key' => $from['key'],
                    'next_steps' => [],
                    'documents' => [],
                ];
                $transitionsByAfterStep[$afterKey]['next_steps'][$nextKey] ??= $to['key'];
                $transitionsByAfterStep[$afterKey]['documents'][$documentUuid] = true;
            }
        }

        $totalDocuments = count($documentsWithTransitions);
        if ($totalDocuments === 0) {
            return [];
        }

        $candidates = [];
        foreach ($transitionsByAfterStep as $transitionGroup) {
            if (count($transitionGroup['next_steps']) < 2) {
                continue;
            }

            $documentUuids = array_keys($transitionGroup['documents']);
            sort($documentUuids);
            $documentCount = count($documentUuids);

            $candidates[] = new DecisionPointCandidate(
                $transitionGroup['after_step_key'],
                array_values($transitionGroup['next_steps']),
                $documentCount,
                round($documentCount / $totalDocuments, 4),
                $documentUuids
            );
        }

        usort(
            $candidates,
            static fn (DecisionPointCandidate $left, DecisionPointCandidate $right): int => $right->documentCount <=> $left->documentCount
                ?: $left->afterStepKey <=> $right->afterStepKey
        );

        return $candidates;
    }

    /**
     * @param array<int, array{key: string, normalized?: string}|string> $steps
     * @return array<int, array{key: string, normalized: string}>
     */
    private function normalizedSteps(array $steps): array
    {
        $result = [];
        $previousNormalizedStepKey = null;
        foreach ($steps as $step) {
            $stepKey = is_array($step) ? (string) ($step['key'] ?? '') : (string) $step;
            $normalizedStepKey = is_array($step)
                ? (string) ($step['normalized'] ?? StepKeyNormalizer::normalize($stepKey))
                : StepKeyNormalizer::normalize($stepKey);
            if ($stepKey === '' || $normalizedStepKey === '') {
                continue;
            }
            if ($normalizedStepKey === $previousNormalizedStepKey) {
                continue;
            }

            $result[] = [
                'key' => $stepKey,
                'normalized' => $normalizedStepKey,
            ];
            $previousNormalizedStepKey = $normalizedStepKey;
        }

        return $result;
    }
}
