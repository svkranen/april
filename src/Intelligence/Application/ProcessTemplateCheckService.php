<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateArrayFactory;
use App\Intelligence\Domain\ProcessTemplateDecisionPoint;
use App\Intelligence\Domain\ProcessTemplateDecisionRuleEvaluator;
use App\Intelligence\Domain\ProcessTemplateParallelGroup;
use App\Intelligence\Domain\ProcessTemplateStep;

final class ProcessTemplateCheckService
{
    public function __construct(
        private readonly DocumentTimelineProvider $timelineProvider,
        private readonly ProcessTemplateDecisionRuleEvaluator $decisionRuleEvaluator = new ProcessTemplateDecisionRuleEvaluator()
    ) {
    }

    /**
     * @param array<string, mixed> $templateData
     */
    public function check(
        string $documentUuid,
        string $processKey,
        array $templateData,
        ?int $documentVersion = null,
        EventTimelineOrder $order = EventTimelineOrder::DEFAULT
    ): ProcessTemplateCheckResult {
        return $this->checkDocument(
            $documentUuid,
            $processKey,
            ProcessTemplateArrayFactory::fromArray($templateData),
            $documentVersion,
            $order
        );
    }

    public function checkDocument(
        string $documentUuid,
        string $processKey,
        ProcessTemplate $template,
        ?int $documentVersion = null,
        EventTimelineOrder $order = EventTimelineOrder::DEFAULT
    ): ProcessTemplateCheckResult {
        $parallelGroups = $this->parallelGroups($template);
        $expectedSteps = $this->expectedSteps($template, $parallelGroups);
        $knownSteps = $this->knownSteps($template, $parallelGroups, $expectedSteps);
        $actualStepEntries = $this->actualStepEntries($documentUuid, $processKey, $documentVersion, $order);
        $actualSteps = array_map(
            static fn (array $entry): string => $entry['step'],
            $actualStepEntries
        );
        $deviations = $this->deviations($expectedSteps, $actualSteps, $parallelGroups, $knownSteps);
        $deviations = array_merge($deviations, $this->decisionDeviations($template->decisionPoints, $actualStepEntries));
        $parallelGroupMessages = $this->parallelGroupMessages($parallelGroups, $actualSteps);

        return new ProcessTemplateCheckResult($expectedSteps, $actualSteps, $deviations, $parallelGroupMessages);
    }

    /**
     * @param array<int, ProcessTemplateParallelGroup> $parallelGroups
     * @return array<int, string>
     */
    private function expectedSteps(ProcessTemplate $template, array $parallelGroups): array
    {
        $stepKeys = $template->requiredStepKeys !== []
            ? $template->requiredStepKeys
            : $this->templateStepKeys($template);

        foreach ($parallelGroups as $group) {
            $missingRequiredSteps = array_values(array_filter(
                $group->requiredStepKeys,
                static fn (string $stepKey): bool => !in_array($stepKey, $stepKeys, true)
            ));
            if ($missingRequiredSteps === []) {
                continue;
            }

            $insertPosition = count($stepKeys);
            if ($group->after !== null) {
                $afterPosition = array_search($group->after, $stepKeys, true);
                if ($afterPosition !== false) {
                    $insertPosition = $afterPosition + 1;
                }
            }

            array_splice($stepKeys, $insertPosition, 0, $missingRequiredSteps);
        }

        return $stepKeys;
    }

    /**
     * @return array<int, string>
     */
    private function templateStepKeys(ProcessTemplate $template): array
    {
        return array_map(
            static fn (ProcessTemplateStep $step): string => $step->key,
            $template->steps
        );
    }

    /**
     * @param array<int, ProcessTemplateParallelGroup> $parallelGroups
     * @param array<int, string> $expectedSteps
     * @return array<int, string>
     */
    private function knownSteps(ProcessTemplate $template, array $parallelGroups, array $expectedSteps): array
    {
        $knownSteps = $this->templateStepKeys($template);

        foreach ($template->requiredStepKeys as $stepKey) {
            $knownSteps[] = $stepKey;
        }

        foreach ($parallelGroups as $group) {
            foreach ($group->requiredStepKeys as $stepKey) {
                $knownSteps[] = $stepKey;
            }
        }

        if ($knownSteps === []) {
            $knownSteps = $expectedSteps;
        }

        return array_values(array_unique($knownSteps));
    }

    /**
     * @return array<int, ProcessTemplateParallelGroup>
     */
    private function parallelGroups(ProcessTemplate $template): array
    {
        $parallelGroups = [];
        foreach ($template->parallelGroups as $group) {
            if ($group->order !== 'any' || $group->requiredStepKeys === []) {
                continue;
            }

            $parallelGroups[] = $group;
        }

        return $parallelGroups;
    }

    /**
     * @return array<int, array{step: string, context: array<string, mixed>|null}>
     */
    private function actualStepEntries(string $documentUuid, string $processKey, ?int $documentVersion, EventTimelineOrder $order): array
    {
        $events = array_values(array_filter(
            $this->timelineProvider->build($documentUuid, $order)->events,
            static fn (DocumentTimelineEventRow $event): bool => $event->processKey === $processKey
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

        $entries = [];
        $previousStepKey = null;
        foreach ($events as $event) {
            if ($event->stepKey === $previousStepKey) {
                continue;
            }

            $entries[] = [
                'step' => $event->stepKey,
                'context' => $this->contextFromSummary($event->contextSummary),
            ];
            $previousStepKey = $event->stepKey;
        }

        return $entries;
    }

    /**
     * @param array<string, mixed>|null $contextSummary
     * @return array<string, mixed>|null
     */
    private function contextFromSummary(?array $contextSummary): ?array
    {
        if ($contextSummary === null) {
            return null;
        }

        $attributes = $contextSummary['attributes'] ?? null;

        return is_array($attributes) ? $attributes : null;
    }

    /**
     * @param array<int, ProcessTemplateDecisionPoint> $decisionPoints
     * @param array<int, array{step: string, context: array<string, mixed>|null}> $actualStepEntries
     * @return array<int, string>
     */
    private function decisionDeviations(array $decisionPoints, array $actualStepEntries): array
    {
        $deviations = [];
        if ($decisionPoints === []) {
            return $deviations;
        }

        foreach ($decisionPoints as $decisionPoint) {
            if ($decisionPoint->after === null) {
                continue;
            }

            $position = $this->stepPosition($actualStepEntries, $decisionPoint->after);
            if ($position === null) {
                $deviations[] = sprintf(
                    'Decision rule violation: %s after step %s not found',
                    $decisionPoint->key,
                    $decisionPoint->after
                );
                continue;
            }

            $context = $actualStepEntries[$position]['context'];
            $missingContextFields = $this->missingContextFields($decisionPoint, $context);
            if ($missingContextFields !== []) {
                $deviations[] = sprintf(
                    'Missing context for decision point %s: %s',
                    $decisionPoint->key,
                    implode(', ', $missingContextFields)
                );
                continue;
            }

            if ($context === null) {
                continue;
            }

            $expectedNextStepKey = $this->decisionRuleEvaluator->expectedNextStepKey($decisionPoint, $context);
            if ($expectedNextStepKey === null) {
                continue;
            }

            $actualNextStepKey = $actualStepEntries[$position + 1]['step'] ?? null;
            if ($actualNextStepKey !== $expectedNextStepKey) {
                $deviations[] = sprintf(
                    'Decision rule violation: %s expected %s but got %s',
                    $decisionPoint->key,
                    $expectedNextStepKey,
                    $actualNextStepKey ?? 'none'
                );
            }
        }

        return $deviations;
    }

    /**
     * @param array<string, mixed>|null $context
     * @return array<int, string>
     */
    private function missingContextFields(ProcessTemplateDecisionPoint $decisionPoint, ?array $context): array
    {
        if ($decisionPoint->requiredFields === []) {
            return [];
        }

        if ($context === null) {
            return $decisionPoint->requiredFields;
        }

        return array_values(array_filter(
            $decisionPoint->requiredFields,
            static fn (string $field): bool => !array_key_exists($field, $context) || $context[$field] === null
        ));
    }

    /**
     * @param array<int, array{step: string, context: array<string, mixed>|null}> $actualStepEntries
     */
    private function stepPosition(array $actualStepEntries, string $stepKey): ?int
    {
        foreach ($actualStepEntries as $position => $entry) {
            if ($entry['step'] === $stepKey) {
                return $position;
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $expectedSteps
     * @param array<int, string> $actualSteps
     * @param array<int, ProcessTemplateParallelGroup> $parallelGroups
     * @param array<int, string> $knownSteps
     * @return array<int, string>
     */
    private function deviations(array $expectedSteps, array $actualSteps, array $parallelGroups, array $knownSteps): array
    {
        $deviations = [];

        foreach (array_values(array_diff($expectedSteps, $actualSteps)) as $stepKey) {
            $deviations[] = sprintf('Missing step: %s', $stepKey);
        }

        foreach (array_values(array_diff($actualSteps, $knownSteps)) as $stepKey) {
            $deviations[] = sprintf('Unexpected step: %s', $stepKey);
        }

        if (!$this->isExpectedOrder($expectedSteps, $actualSteps, $parallelGroups)) {
            $deviations[] = 'Wrong order';
        }

        foreach ($parallelGroups as $group) {
            $missingRequiredSteps = array_values(array_diff($group->requiredStepKeys, $actualSteps));
            if ($missingRequiredSteps !== []) {
                $deviations[] = sprintf(
                    'Parallel Group incomplete: %s (missing: %s)',
                    $group->key,
                    implode(', ', $missingRequiredSteps)
                );
            }
        }

        return $deviations;
    }

    /**
     * @param array<int, string> $expectedSteps
     * @param array<int, string> $actualSteps
     * @param array<int, ProcessTemplateParallelGroup> $parallelGroups
     */
    private function isExpectedOrder(array $expectedSteps, array $actualSteps, array $parallelGroups): bool
    {
        $stepGroups = $this->parallelStepGroups($parallelGroups);
        $actualPositions = [];
        foreach ($actualSteps as $position => $stepKey) {
            $actualPositions[$stepKey] ??= $position;
        }

        for ($i = 0, $max = count($expectedSteps) - 1; $i < $max; ++$i) {
            for ($j = $i + 1, $stepsCount = count($expectedSteps); $j < $stepsCount; ++$j) {
                $leftStepKey = $expectedSteps[$i];
                $rightStepKey = $expectedSteps[$j];

                if (!isset($actualPositions[$leftStepKey], $actualPositions[$rightStepKey])) {
                    continue;
                }

                if ($this->sameAnyOrderParallelGroup($leftStepKey, $rightStepKey, $stepGroups)) {
                    continue;
                }

                if ($actualPositions[$leftStepKey] > $actualPositions[$rightStepKey]) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param array<string, string> $stepGroups
     */
    private function sameAnyOrderParallelGroup(string $leftStepKey, string $rightStepKey, array $stepGroups): bool
    {
        return isset($stepGroups[$leftStepKey], $stepGroups[$rightStepKey])
            && $stepGroups[$leftStepKey] === $stepGroups[$rightStepKey];
    }

    /**
     * @param array<int, ProcessTemplateParallelGroup> $parallelGroups
     * @return array<string, string>
     */
    private function parallelStepGroups(array $parallelGroups): array
    {
        $stepGroups = [];
        foreach ($parallelGroups as $group) {
            foreach ($group->requiredStepKeys as $stepKey) {
                $stepGroups[$stepKey] = $group->key;
            }
        }

        return $stepGroups;
    }

    /**
     * @param array<int, ProcessTemplateParallelGroup> $parallelGroups
     * @param array<int, string> $actualSteps
     * @return array<int, string>
     */
    private function parallelGroupMessages(array $parallelGroups, array $actualSteps): array
    {
        $messages = [];
        foreach ($parallelGroups as $group) {
            $missingRequiredSteps = array_values(array_diff($group->requiredStepKeys, $actualSteps));
            if ($missingRequiredSteps === []) {
                $messages[] = sprintf('Parallel Group satisfied: %s', $group->key);

                continue;
            }

            $messages[] = sprintf(
                'Parallel Group incomplete: %s (missing: %s)',
                $group->key,
                implode(', ', $missingRequiredSteps)
            );
        }

        return $messages;
    }
}
