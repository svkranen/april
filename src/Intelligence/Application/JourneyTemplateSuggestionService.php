<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateStep;
use App\Intelligence\Domain\ProcessTemplateSuggestionNote;
use App\Intelligence\Domain\ProcessTemplateSuggestionResult;
use App\Intelligence\Domain\ProcessTemplateSuggestionWarning;
use App\Intelligence\Domain\ProcessTemplateTransition;

final class JourneyTemplateSuggestionService
{
    public function __construct(
        private readonly DocumentTimelineProvider $timelineProvider
    ) {
    }

    public function suggest(
        string $documentUuid,
        string $templateKey,
        ?ProcessTemplate $existingTemplate = null,
        ?int $documentVersion = null,
        bool $includeBefore = false,
        EventTimelineOrder $order = EventTimelineOrder::DEFAULT
    ): ?ProcessTemplateSuggestionResult {
        $timeline = $this->timelineProvider->build($documentUuid, $order);
        $events = array_values(array_filter(
            $timeline->events,
            static fn (DocumentTimelineEventRow $event): bool => $includeBefore || $event->eventPhase === 'after'
        ));

        if ($events === []) {
            return null;
        }

        $selectedVersion = $documentVersion ?? max(array_map(
            static fn (DocumentTimelineEventRow $event): int => $event->documentVersion,
            $events
        ));

        $events = array_values(array_filter(
            $events,
            static fn (DocumentTimelineEventRow $event): bool => $event->documentVersion === $selectedVersion
        ));

        if ($events === []) {
            return null;
        }

        usort($events, static fn (DocumentTimelineEventRow $left, DocumentTimelineEventRow $right): int => $order->compareTimelineRows($left, $right));

        $warnings = [];
        $observedProcessKeys = $this->observedProcessKeys($events, $documentUuid, $warnings);
        if ($observedProcessKeys === []) {
            return new ProcessTemplateSuggestionResult(
                $this->baseTemplate($templateKey, $existingTemplate, [], []),
                [$documentUuid],
                warnings: $warnings
            );
        }

        $stepPlan = $this->stepPlan($observedProcessKeys, $existingTemplate);
        $transitions = $this->mergeTransitions(
            $existingTemplate?->transitions ?? [],
            $this->transitions($stepPlan['sequence'])
        );

        $suggestions = [];
        foreach ($stepPlan['added_steps'] as $step) {
            $suggestions[] = new ProcessTemplateSuggestionNote(
                'journey_step_suggested',
                sprintf('Observed process "%s" is suggested as journey step "%s".', $step->processKey, $step->key),
                eventKey: $step->key,
                documentUuids: [$documentUuid]
            );
        }

        foreach ($this->newTransitions($existingTemplate?->transitions ?? [], $this->transitions($stepPlan['sequence'])) as $transition) {
            $suggestions[] = new ProcessTemplateSuggestionNote(
                'journey_transition_suggested',
                sprintf('Observed process order suggests transition "%s" -> "%s".', $transition->from, $transition->to),
                afterStepKey: $transition->from,
                observedNextSteps: $transition->to === null ? [] : [$transition->to],
                documentUuids: [$documentUuid]
            );
        }

        foreach ($this->orderWarnings($existingTemplate, $this->transitions($stepPlan['sequence']), $documentUuid) as $warning) {
            $warnings[] = $warning;
        }

        return new ProcessTemplateSuggestionResult(
            $this->baseTemplate(
                $templateKey,
                $existingTemplate,
                $this->mergeSteps($existingTemplate?->steps ?? [], $stepPlan['added_steps']),
                $transitions
            ),
            [$documentUuid],
            warnings: $warnings,
            suggestions: $suggestions
        );
    }

    /**
     * @param array<int, DocumentTimelineEventRow> $events
     * @param array<int, ProcessTemplateSuggestionWarning> $warnings
     * @return array<int, string>
     */
    private function observedProcessKeys(array $events, string $documentUuid, array &$warnings): array
    {
        $processKeys = [];
        $previous = null;
        $missing = 0;

        foreach ($events as $event) {
            $processKey = trim($event->processKey);
            if ($processKey === '') {
                ++$missing;
                continue;
            }

            if ($processKey === $previous) {
                continue;
            }

            $processKeys[] = $processKey;
            $previous = $processKey;
        }

        if ($missing > 0) {
            $warnings[] = new ProcessTemplateSuggestionWarning(
                'missing_process_key',
                sprintf('%d timeline event(s) had no processKey and were ignored for the journey suggestion.', $missing),
                [$documentUuid]
            );
        }

        return $processKeys;
    }

    /**
     * @param array<int, string> $processKeys
     * @return array{sequence: array<int, string>, added_steps: array<int, ProcessTemplateStep>}
     */
    private function stepPlan(array $processKeys, ?ProcessTemplate $existingTemplate): array
    {
        $existingSteps = $existingTemplate?->steps ?? [];
        $availableExistingByProcessKey = [];
        $usedKeys = [];

        foreach ($existingSteps as $step) {
            $usedKeys[$step->key] = true;
            if ($step->type === 'process' && $step->processKey !== null && trim($step->processKey) !== '') {
                $availableExistingByProcessKey[$step->processKey][] = $step->key;
            }
        }

        $occurrences = [];
        $sequence = [];
        $addedSteps = [];

        foreach ($processKeys as $processKey) {
            $existingKey = null;
            if (isset($availableExistingByProcessKey[$processKey])) {
                $existingKey = array_shift($availableExistingByProcessKey[$processKey]);
            }
            if ($existingKey !== null) {
                $sequence[] = $existingKey;
                continue;
            }

            $occurrences[$processKey] = ($occurrences[$processKey] ?? 0) + 1;
            $stepKey = $this->uniqueStepKey($processKey, $occurrences[$processKey], $usedKeys);
            $usedKeys[$stepKey] = true;
            $sequence[] = $stepKey;
            $addedSteps[] = new ProcessTemplateStep(
                $stepKey,
                type: 'process',
                processKey: $processKey,
                required: true
            );
        }

        return [
            'sequence' => $sequence,
            'added_steps' => $addedSteps,
        ];
    }

    /**
     * @param array<string, bool> $usedKeys
     */
    private function uniqueStepKey(string $processKey, int $occurrence, array $usedKeys): string
    {
        $base = trim($processKey);
        $suffix = $occurrence;

        do {
            $candidate = $suffix === 1 ? $base : sprintf('%s_%d', $base, $suffix);
            ++$suffix;
        } while (isset($usedKeys[$candidate]));

        return $candidate;
    }

    /**
     * @param array<int, ProcessTemplateStep> $existingSteps
     * @param array<int, ProcessTemplateStep> $addedSteps
     * @return array<int, ProcessTemplateStep>
     */
    private function mergeSteps(array $existingSteps, array $addedSteps): array
    {
        if ($existingSteps === []) {
            return $addedSteps;
        }

        return array_values([...$existingSteps, ...$addedSteps]);
    }

    /**
     * @param array<int, string> $stepKeys
     * @return array<int, ProcessTemplateTransition>
     */
    private function transitions(array $stepKeys): array
    {
        $transitions = [];
        for ($i = 0, $max = count($stepKeys) - 1; $i < $max; ++$i) {
            if ($stepKeys[$i] === $stepKeys[$i + 1]) {
                continue;
            }

            $transitions[] = new ProcessTemplateTransition($stepKeys[$i], $stepKeys[$i + 1]);
        }

        return $transitions;
    }

    /**
     * @param array<int, ProcessTemplateTransition> $existingTransitions
     * @param array<int, ProcessTemplateTransition> $observedTransitions
     * @return array<int, ProcessTemplateTransition>
     */
    private function mergeTransitions(array $existingTransitions, array $observedTransitions): array
    {
        return array_values([...$existingTransitions, ...$this->newTransitions($existingTransitions, $observedTransitions)]);
    }

    /**
     * @param array<int, ProcessTemplateTransition> $existingTransitions
     * @param array<int, ProcessTemplateTransition> $observedTransitions
     * @return array<int, ProcessTemplateTransition>
     */
    private function newTransitions(array $existingTransitions, array $observedTransitions): array
    {
        $known = [];
        foreach ($existingTransitions as $transition) {
            if ($transition->to !== null) {
                $known[$transition->from."\0".$transition->to] = true;
            }
        }

        $new = [];
        foreach ($observedTransitions as $transition) {
            if ($transition->to === null || isset($known[$transition->from."\0".$transition->to])) {
                continue;
            }

            $known[$transition->from."\0".$transition->to] = true;
            $new[] = $transition;
        }

        return $new;
    }

    /**
     * @param array<int, ProcessTemplateTransition> $observedTransitions
     * @return array<int, ProcessTemplateSuggestionWarning>
     */
    private function orderWarnings(?ProcessTemplate $existingTemplate, array $observedTransitions, string $documentUuid): array
    {
        if ($existingTemplate === null || $existingTemplate->transitions === []) {
            return [];
        }

        $known = [];
        foreach ($existingTemplate->transitions as $transition) {
            if ($transition->to !== null) {
                $known[$transition->from."\0".$transition->to] = true;
            }
        }

        $warnings = [];
        foreach ($observedTransitions as $transition) {
            if ($transition->to === null || isset($known[$transition->from."\0".$transition->to])) {
                continue;
            }

            $warnings[] = new ProcessTemplateSuggestionWarning(
                'observed_journey_order_differs',
                sprintf(
                    'Observed journey order "%s" -> "%s" is not present in the existing template transitions.',
                    $transition->from,
                    $transition->to
                ),
                [$documentUuid]
            );
        }

        return $warnings;
    }

    /**
     * @param array<int, ProcessTemplateStep> $steps
     * @param array<int, ProcessTemplateTransition> $transitions
     */
    private function baseTemplate(string $templateKey, ?ProcessTemplate $existingTemplate, array $steps, array $transitions): ProcessTemplate
    {
        return new ProcessTemplate(
            $existingTemplate?->key ?? $templateKey,
            $existingTemplate?->version ?? 'draft',
            $existingTemplate?->name,
            $existingTemplate?->initialStepKey,
            steps: $steps,
            transitions: $transitions,
            parallelGroups: $existingTemplate?->parallelGroups ?? [],
            contextProfileRequiredFields: $existingTemplate?->contextProfileRequiredFields ?? [],
            fieldMappings: $existingTemplate?->fieldMappings ?? [],
            decisionPoints: $existingTemplate?->decisionPoints ?? [],
            requiredStepKeys: $existingTemplate?->requiredStepKeys ?? [],
            connector: $existingTemplate?->connector,
            contextPolicy: $existingTemplate?->contextPolicy,
            signChecks: $existingTemplate?->signChecks ?? [],
            accessProbes: $existingTemplate?->accessProbes ?? [],
            visibilityProfiles: $existingTemplate?->visibilityProfiles ?? [],
            visibilityProfileResolvers: $existingTemplate?->visibilityProfileResolvers ?? [],
            visibilityRetryPolicies: $existingTemplate?->visibilityRetryPolicies ?? [],
            manualAccessTests: $existingTemplate?->manualAccessTests ?? [],
            crossProcessRoutingRules: $existingTemplate?->crossProcessRoutingRules ?? [],
            match: $existingTemplate?->match,
            scope: 'journey',
            sourceSystem: $existingTemplate?->sourceSystem ?? 'amagno'
        );
    }
}
