<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateParallelGroup;
use App\Intelligence\Domain\ProcessTemplateStep;
use App\Intelligence\Domain\ProcessTemplateSuggestionNote;
use App\Intelligence\Domain\ProcessTemplateSuggestionResult;
use App\Intelligence\Domain\ProcessTemplateSuggestionWarning;
use App\Intelligence\Domain\ProcessTemplateTransition;
use App\Intelligence\Domain\SuggestedTransition;

final class ProcessTemplateMultiDocumentSuggestionService
{
    private readonly DecisionPointCandidateDetector $decisionPointCandidateDetector;

    public function __construct(
        private readonly DocumentTimelineProvider $timelineProvider,
        private readonly ProcessDocumentUuidProvider $documentUuidProvider,
        ?DecisionPointCandidateDetector $decisionPointCandidateDetector = null
    ) {
        $this->decisionPointCandidateDetector = $decisionPointCandidateDetector ?? new DecisionPointCandidateDetector();
    }

    /**
     * @return array<int, string>
     */
    public function documentUuidsForProcess(string $processKey, ?\DateTimeImmutable $since = null, ?int $limit = null): array
    {
        return $this->documentUuidProvider->documentUuidsForProcess($processKey, $since, $limit);
    }

    /**
     * @param array<int, string> $documentUuids
     */
    public function suggest(
        array $documentUuids,
        string $processKey,
        ?int $documentVersion = null,
        bool $includeBefore = false,
        EventTimelineOrder $order = EventTimelineOrder::DEFAULT
    ): ?ProcessTemplateSuggestionResult
    {
        $steps = [];
        $transitions = [];
        $sequences = [];
        $usedDocumentUuids = [];

        foreach ($documentUuids as $documentUuid) {
            $sequence = $this->stepSequenceForDocument($documentUuid, $processKey, $documentVersion, $includeBefore, $order);
            if ($sequence === []) {
                continue;
            }
            $usedDocumentUuids[] = $documentUuid;
            $sequences[] = [
                'document_uuid' => $documentUuid,
                'steps' => $sequence,
            ];

            foreach ($sequence as $step) {
                $steps[$step['normalized']] ??= $step['key'];
            }

            for ($i = 0, $max = count($sequence) - 1; $i < $max; ++$i) {
                $from = $sequence[$i];
                $to = $sequence[$i + 1];
                if ($from['normalized'] === $to['normalized']) {
                    continue;
                }

                $transitionKey = $from['normalized'] . "\0" . $to['normalized'];
                if (!isset($transitions[$transitionKey])) {
                    $transitions[$transitionKey] = [
                        'from' => $from['key'],
                        'to' => $to['key'],
                        'from_normalized' => $from['normalized'],
                        'to_normalized' => $to['normalized'],
                        'observed_count' => 0,
                    ];
                }

                ++$transitions[$transitionKey]['observed_count'];
            }
        }

        if ($steps === []) {
            return null;
        }

        $maxObservedCount = $this->maxObservedCount($transitions);
        $parallelGroups = $this->suggestedParallelGroups($sequences, $steps, $transitions);
        $warnings = $this->conflictingTransitionWarnings($transitions);
        $suggestions = [];
        foreach ($parallelGroups as $parallelGroup) {
            $warnings[] = new ProcessTemplateSuggestionWarning(
                'possible_parallel',
                sprintf(
                    'Possible parallel steps detected: %s. Documents: %s.',
                    implode(', ', $parallelGroup['required_steps']),
                    implode(', ', $parallelGroup['document_uuids'])
                ),
                $parallelGroup['document_uuids']
            );
            $suggestions[] = new ProcessTemplateSuggestionNote(
                'possible_parallel_group',
                $parallelGroup['reason'],
                $parallelGroup['key'],
                $parallelGroup['document_uuids'],
                $parallelGroup['confidence']
            );
        }
        foreach ($this->decisionPointCandidateDetector->detect($sequences) as $candidate) {
            $suggestions[] = new ProcessTemplateSuggestionNote(
                'possible_decision_point',
                sprintf(
                    'Multiple next steps observed after %s. Context fields may be required to explain routing.',
                    $candidate->afterStepKey
                ),
                afterStepKey: $candidate->afterStepKey,
                observedNextSteps: $candidate->observedNextSteps
            );
        }

        return new ProcessTemplateSuggestionResult(
            new ProcessTemplate(
                $processKey,
                'draft',
                steps: array_map(
                    static fn (string $stepKey): ProcessTemplateStep => new ProcessTemplateStep($stepKey),
                    array_values($steps)
                ),
                transitions: array_map(
                    static fn (array $transition): ProcessTemplateTransition => new ProcessTemplateTransition($transition['from'], $transition['to']),
                    array_values($transitions)
                ),
                parallelGroups: array_map(
                    static fn (array $parallelGroup): ProcessTemplateParallelGroup => new ProcessTemplateParallelGroup(
                        $parallelGroup['key'],
                        $parallelGroup['after'] ?? null,
                        $parallelGroup['required_steps'],
                        $parallelGroup['order']
                    ),
                    $parallelGroups
                ),
                decisionPoints: []
            ),
            $usedDocumentUuids,
            $this->publicTransitions($transitions, $maxObservedCount),
            $warnings,
            $suggestions
        );
    }

    /**
     * @return array<int, array{key: string, normalized: string}>
     */
    private function stepSequenceForDocument(
        string $documentUuid,
        string $processKey,
        ?int $documentVersion,
        bool $includeBefore,
        EventTimelineOrder $order
    ): array
    {
        $events = array_values(array_filter(
            $this->timelineProvider->build($documentUuid, $order)->events,
            static fn (DocumentTimelineEventRow $event): bool => $event->processKey === $processKey
                && ($includeBefore || $event->eventPhase === 'after')
        ));

        if ($events === []) {
            return [];
        }

        $selectedVersion = $documentVersion ?? max(array_map(
            static fn (DocumentTimelineEventRow $event): int => $event->documentVersion,
            $events
        ));

        $events = array_values(array_filter(
            $events,
            static fn (DocumentTimelineEventRow $event): bool => $event->documentVersion === $selectedVersion
        ));

        usort($events, static fn (DocumentTimelineEventRow $left, DocumentTimelineEventRow $right): int => $order->compareTimelineRows($left, $right));

        $sequence = [];
        $previousNormalizedStepKey = null;
        foreach ($events as $event) {
            $normalizedStepKey = StepKeyNormalizer::normalize($event->stepKey);
            if ($normalizedStepKey === $previousNormalizedStepKey) {
                continue;
            }

            $sequence[] = [
                'key' => $event->stepKey,
                'normalized' => $normalizedStepKey,
            ];
            $previousNormalizedStepKey = $normalizedStepKey;
        }

        return $sequence;
    }

    /**
     * @param array<string, array{observed_count: int}> $transitions
     */
    private function maxObservedCount(array $transitions): int
    {
        if ($transitions === []) {
            return 0;
        }

        return max(array_map(
            static fn (array $transition): int => $transition['observed_count'],
            $transitions
        ));
    }

    /**
     * @param array<string, array{from: string, to: string, from_normalized: string, to_normalized: string, observed_count: int}> $transitions
     * @return array<int, SuggestedTransition>
     */
    private function publicTransitions(array $transitions, int $maxObservedCount): array
    {
        return array_values(array_map(
            static fn (array $transition): SuggestedTransition => new SuggestedTransition(
                $transition['from'],
                $transition['to'],
                $transition['observed_count'],
                $maxObservedCount === 0 ? 0.0 : round($transition['observed_count'] / $maxObservedCount, 4)
            ),
            $transitions
        ));
    }

    /**
     * @param array<string, array{from: string, to: string, from_normalized: string, to_normalized: string, observed_count: int}> $transitions
     * @return array<int, ProcessTemplateSuggestionWarning>
     */
    private function conflictingTransitionWarnings(array $transitions): array
    {
        $warnings = [];
        $seenPairs = [];

        foreach ($transitions as $transition) {
            $reverseKey = $transition['to_normalized'] . "\0" . $transition['from_normalized'];
            if (!isset($transitions[$reverseKey])) {
                continue;
            }

            $pair = [$transition['from_normalized'], $transition['to_normalized']];
            sort($pair);
            $pairKey = implode("\0", $pair);
            if (isset($seenPairs[$pairKey])) {
                continue;
            }
            $seenPairs[$pairKey] = true;

            $reverse = $transitions[$reverseKey];
            $warnings[] = new ProcessTemplateSuggestionWarning(
                'conflicting_transition',
                sprintf(
                    'Observed both %s -> %s and %s -> %s',
                    $transition['from'],
                    $transition['to'],
                    $reverse['from'],
                    $reverse['to']
                )
            );
        }

        return $warnings;
    }

    /**
     * @param array<int, array{document_uuid: string, steps: array<int, array{key: string, normalized: string}>}> $sequences
     * @param array<string, string> $steps
     * @param array<string, array{from: string, to: string, from_normalized: string, to_normalized: string, observed_count: int}> $transitions
     * @return array<int, array{key: string, after?: string, required_steps: array<int, string>, order: string, confidence: float, reason: string, document_uuids: array<int, string>}>
     */
    private function suggestedParallelGroups(array $sequences, array $steps, array $transitions): array
    {
        $pairs = [];
        $firstSteps = [];
        $lastSteps = [];
        $stepOccurrences = [];
        $directTransitionDocuments = [];

        foreach ($sequences as $documentSequence) {
            $documentUuid = $documentSequence['document_uuid'];
            $sequence = $documentSequence['steps'];

            for ($i = 0, $max = count($sequence) - 1; $i < $max; ++$i) {
                $from = $sequence[$i]['normalized'];
                $to = $sequence[$i + 1]['normalized'];
                if ($from === $to) {
                    continue;
                }

                $directTransitionDocuments[$from . "\0" . $to][$documentUuid] = true;
            }

            $uniqueSequence = $this->uniqueSequence($documentSequence['steps']);
            if ($uniqueSequence === []) {
                continue;
            }

            $firstSteps[$uniqueSequence[0]['normalized']][$documentUuid] = true;
            $lastSteps[$uniqueSequence[count($uniqueSequence) - 1]['normalized']][$documentUuid] = true;
            foreach ($uniqueSequence as $step) {
                $stepOccurrences[$step['normalized']][$documentUuid] = true;
            }

            for ($i = 0, $max = count($uniqueSequence) - 1; $i < $max; ++$i) {
                for ($j = $i + 1, $count = count($uniqueSequence); $j < $count; ++$j) {
                    $left = $uniqueSequence[$i]['normalized'];
                    $right = $uniqueSequence[$j]['normalized'];
                    if ($left === $right) {
                        continue;
                    }

                    $pair = [$left, $right];
                    sort($pair);
                    $pairKey = implode("\0", $pair);
                    $orderKey = $left . "\0" . $right;

                    $pairs[$pairKey] ??= [
                        'steps' => $pair,
                        'orders' => [],
                        'documents' => [],
                        'direct_predecessors_before_pair' => [],
                        'direct_successors_after_pair' => [],
                    ];
                    $pairs[$pairKey]['orders'][$orderKey] = ($pairs[$pairKey]['orders'][$orderKey] ?? 0) + 1;
                    $pairs[$pairKey]['documents'][$documentUuid] = true;

                    $directPredecessor = $i > 0 ? $uniqueSequence[$i - 1]['normalized'] : null;
                    if ($directPredecessor !== null) {
                        $pairs[$pairKey]['direct_predecessors_before_pair'][$directPredecessor][$orderKey] = true;
                    }

                    $directSuccessor = $j + 1 < count($uniqueSequence) ? $uniqueSequence[$j + 1]['normalized'] : null;
                    if ($directSuccessor !== null) {
                        $pairs[$pairKey]['direct_successors_after_pair'][$directSuccessor][$orderKey] = true;
                    }
                }
            }
        }

        $groups = [];
        $groupedPairKeys = [];
        foreach ($pairs as $pairKey => $pair) {
            if (count($pair['documents']) < 2 || count($pair['orders']) < 2) {
                continue;
            }

            [$left, $right] = $pair['steps'];
            if ($this->isStableBoundaryStep($left, $stepOccurrences, $firstSteps, $lastSteps)
                || $this->isStableBoundaryStep($right, $stepOccurrences, $firstSteps, $lastSteps)) {
                continue;
            }

            $forward = $pair['orders'][$left . "\0" . $right] ?? 0;
            $reverse = $pair['orders'][$right . "\0" . $left] ?? 0;
            if ($forward === 0 || $reverse === 0) {
                continue;
            }

            $hasSharedDirectBoundary = $this->hasSharedDirectBoundary($pair, $left . "\0" . $right, $right . "\0" . $left);
            if (!$hasSharedDirectBoundary && !$this->hasObservedBidirectionalDirectTransitions($transitions, $left, $right)) {
                continue;
            }

            $forwardOrderKey = $left . "\0" . $right;
            $reverseOrderKey = $right . "\0" . $left;
            $commonPredecessor = $this->commonDirectPredecessor($pair, $forwardOrderKey, $reverseOrderKey);

            $documentUuids = array_keys($pair['documents']);
            sort($documentUuids);
            $total = $forward + $reverse;
            $group = [
                'key' => sprintf('suggested_parallel_%d', count($groups) + 1),
            ];
            if ($commonPredecessor !== null) {
                $group['after'] = $steps[$commonPredecessor] ?? $commonPredecessor;
            }
            $groups[] = $group + [
                'required_steps' => [
                    $steps[$left] ?? $left,
                    $steps[$right] ?? $right,
                ],
                'order' => 'any',
                'confidence' => $total === 0 ? 0.0 : round(min($forward, $reverse) / $total, 4),
                'reason' => $hasSharedDirectBoundary
                    ? 'Observed both orders across document timelines.'
                    : 'Observed bidirectional direct transitions; boundary context varies.',
                'document_uuids' => $documentUuids,
            ];
            $groupedPairKeys[$pairKey] = true;
        }

        return $this->appendBidirectionalFallbackParallelGroups(
            $groups,
            $transitions,
            $steps,
            $stepOccurrences,
            $firstSteps,
            $lastSteps,
            $directTransitionDocuments,
            $groupedPairKeys
        );
    }

    /**
     * @param array<string, array<string, true>> $stepOccurrences
     * @param array<string, array<string, true>> $firstSteps
     * @param array<string, array<string, true>> $lastSteps
     */
    private function isStableBoundaryStep(
        string $step,
        array $stepOccurrences,
        array $firstSteps,
        array $lastSteps
    ): bool {
        $occurrenceCount = count($stepOccurrences[$step] ?? []);

        return $occurrenceCount > 0
            && (
                count($firstSteps[$step] ?? []) > 0
                || count($lastSteps[$step] ?? []) === $occurrenceCount
            );
    }

    /**
     * @param array{
     *     direct_predecessors_before_pair: array<string, array<string, true>>,
     *     direct_successors_after_pair: array<string, array<string, true>>
     * } $pair
     */
    private function hasSharedDirectBoundary(array $pair, string $forwardOrderKey, string $reverseOrderKey): bool
    {
        foreach ($pair['direct_predecessors_before_pair'] as $orders) {
            if (isset($orders[$forwardOrderKey], $orders[$reverseOrderKey])) {
                return true;
            }
        }

        foreach ($pair['direct_successors_after_pair'] as $orders) {
            if (isset($orders[$forwardOrderKey], $orders[$reverseOrderKey])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{direct_predecessors_before_pair: array<string, array<string, true>>} $pair
     */
    private function commonDirectPredecessor(array $pair, string $forwardOrderKey, string $reverseOrderKey): ?string
    {
        $commonPredecessors = [];
        foreach ($pair['direct_predecessors_before_pair'] as $predecessor => $orders) {
            if (isset($orders[$forwardOrderKey], $orders[$reverseOrderKey])) {
                $commonPredecessors[] = $predecessor;
            }
        }

        return count($commonPredecessors) === 1 ? $commonPredecessors[0] : null;
    }

    /**
     * @param array<int, array{key: string, normalized: string}> $sequence
     * @return array<int, array{key: string, normalized: string}>
     */
    private function uniqueSequence(array $sequence): array
    {
        $seen = [];
        $unique = [];

        foreach ($sequence as $step) {
            if (isset($seen[$step['normalized']])) {
                continue;
            }

            $seen[$step['normalized']] = true;
            $unique[] = $step;
        }

        return $unique;
    }

    /**
     * @param array<string, array{observed_count: int}> $transitions
     */
    private function hasObservedBidirectionalDirectTransitions(array $transitions, string $left, string $right): bool
    {
        $forward = $transitions[$left . "\0" . $right]['observed_count'] ?? 0;
        $reverse = $transitions[$right . "\0" . $left]['observed_count'] ?? 0;

        return $forward >= 2 && $reverse >= 2;
    }

    /**
     * @param array<int, array{key: string, after?: string, required_steps: array<int, string>, order: string, confidence: float, reason: string, document_uuids: array<int, string>}> $groups
     * @param array<string, array{from: string, to: string, from_normalized: string, to_normalized: string, observed_count: int}> $transitions
     * @param array<string, string> $steps
     * @param array<string, array<string, true>> $stepOccurrences
     * @param array<string, array<string, true>> $firstSteps
     * @param array<string, array<string, true>> $lastSteps
     * @param array<string, array<string, true>> $directTransitionDocuments
     * @param array<string, true> $groupedPairKeys
     * @return array<int, array{key: string, after?: string, required_steps: array<int, string>, order: string, confidence: float, reason: string, document_uuids: array<int, string>}>
     */
    private function appendBidirectionalFallbackParallelGroups(
        array $groups,
        array $transitions,
        array $steps,
        array $stepOccurrences,
        array $firstSteps,
        array $lastSteps,
        array $directTransitionDocuments,
        array $groupedPairKeys
    ): array {
        $seenPairs = [];

        foreach ($transitions as $transition) {
            $left = $transition['from_normalized'];
            $right = $transition['to_normalized'];
            $reverseKey = $right . "\0" . $left;
            if (!isset($transitions[$reverseKey])) {
                continue;
            }

            $pair = [$left, $right];
            sort($pair);
            $pairKey = implode("\0", $pair);
            if (isset($seenPairs[$pairKey]) || isset($groupedPairKeys[$pairKey])) {
                continue;
            }
            $seenPairs[$pairKey] = true;

            [$first, $second] = $pair;
            if (!$this->hasObservedBidirectionalDirectTransitions($transitions, $first, $second)) {
                continue;
            }

            $documentUuids = array_keys(
                ($directTransitionDocuments[$first . "\0" . $second] ?? [])
                + ($directTransitionDocuments[$second . "\0" . $first] ?? [])
            );
            if (count($documentUuids) < 2) {
                continue;
            }
            sort($documentUuids);

            if ($this->isStableBoundaryPairForFallback($documentUuids, $first, $second, $firstSteps, $lastSteps)) {
                continue;
            }

            $forward = $transitions[$first . "\0" . $second]['observed_count'];
            $reverse = $transitions[$second . "\0" . $first]['observed_count'];
            $total = $forward + $reverse;

            $groups[] = [
                'key' => sprintf('suggested_parallel_%d', count($groups) + 1),
                'required_steps' => [
                    $steps[$first] ?? $first,
                    $steps[$second] ?? $second,
                ],
                'order' => 'any',
                'confidence' => $total === 0 ? 0.0 : round(min($forward, $reverse) / $total, 4),
                'reason' => 'Observed bidirectional direct transitions; boundary context varies.',
                'document_uuids' => $documentUuids,
            ];
        }

        return $groups;
    }

    /**
     * @param array<int, string> $documentUuids
     * @param array<string, array<string, true>> $firstSteps
     * @param array<string, array<string, true>> $lastSteps
     */
    private function isStableBoundaryPairForFallback(
        array $documentUuids,
        string $first,
        string $second,
        array $firstSteps,
        array $lastSteps
    ): bool {
        $documentCount = count($documentUuids);
        if ($documentCount === 0) {
            return true;
        }

        $firstBoundaryDocuments = ($firstSteps[$first] ?? []) + ($firstSteps[$second] ?? []);
        $lastBoundaryDocuments = ($lastSteps[$first] ?? []) + ($lastSteps[$second] ?? []);

        return count(array_intersect_key($firstBoundaryDocuments, array_flip($documentUuids))) === $documentCount
            || count(array_intersect_key($lastBoundaryDocuments, array_flip($documentUuids))) === $documentCount;
    }

}
