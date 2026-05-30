<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateArrayFactory;
use App\Intelligence\Domain\ProcessTemplateDecisionPoint;
use App\Intelligence\Domain\ProcessTemplateDecisionRule;
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
        $expectedSteps = $this->expectedSteps($template);
        $knownSteps = $this->knownSteps($template, $parallelGroups, $expectedSteps);
        $actualStepEntries = $this->actualStepEntries($documentUuid, $processKey, $documentVersion, $order);
        $actualSteps = array_map(
            static fn (array $entry): string => $entry['step'],
            $actualStepEntries
        );
        $deviations = $this->deviations($expectedSteps, $actualSteps, $parallelGroups, $knownSteps);
        $deviations = array_merge($deviations, $this->decisionDeviations($template->decisionPoints, $actualStepEntries, $parallelGroups, $actualSteps));
        $parallelGroupMessages = $this->parallelGroupMessages($parallelGroups, $actualSteps);

        return new ProcessTemplateCheckResult($expectedSteps, $actualSteps, $deviations, $parallelGroupMessages);
    }

    /**
     * @return array<int, string>
     */
    private function expectedSteps(ProcessTemplate $template): array
    {
        return $template->requiredStepKeys !== []
            ? $template->requiredStepKeys
            : $this->templateStepKeys($template);
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
            $context = $this->contextFromSummary($event->contextSummary);
            if ($event->stepKey === $previousStepKey) {
                if ($context !== null && $entries !== []) {
                    $lastIndex = count($entries) - 1;
                    $entries[$lastIndex]['context'] = $this->mergeContexts($entries[$lastIndex]['context'], $context);
                }

                continue;
            }

            $entries[] = [
                'step' => $event->stepKey,
                'context' => $context,
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
     * @param array<string, mixed>|null $left
     * @param array<string, mixed> $right
     * @return array<string, mixed>
     */
    private function mergeContexts(?array $left, array $right): array
    {
        if ($left === null) {
            return $right;
        }

        return array_replace($left, $right);
    }

    /**
     * @param array<int, ProcessTemplateDecisionPoint> $decisionPoints
     * @param array<int, array{step: string, context: array<string, mixed>|null}> $actualStepEntries
     * @param array<int, ProcessTemplateParallelGroup> $parallelGroups
     * @param array<int, string> $actualSteps
     * @return array<int, string>
     */
    private function decisionDeviations(array $decisionPoints, array $actualStepEntries, array $parallelGroups, array $actualSteps): array
    {
        $deviations = [];
        if ($decisionPoints === []) {
            return $deviations;
        }

        $expectedDecisionStepKeys = [];
        foreach ($decisionPoints as $decisionPoint) {
            if ($decisionPoint->after === null) {
                continue;
            }

            $position = $this->stepPosition($actualStepEntries, $decisionPoint->after);
            if ($position === null) {
                if (!in_array($decisionPoint->after, $expectedDecisionStepKeys, true)) {
                    continue;
                }

                $deviations[] = sprintf(
                    'Decision rule violation: %s after step %s not found',
                    $decisionPoint->key,
                    $decisionPoint->after
                );
                continue;
            }

            $context = $this->contextForDecisionPoint($actualStepEntries, $position, $decisionPoint->requiredFields);
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

            $matchedRule = $this->decisionRuleEvaluator->matchingRule($decisionPoint, $context);
            if ($matchedRule === null) {
                continue;
            }

            $expectedNextStepKey = $matchedRule->expectedNextStepKey;
            $expectedDecisionStepKeys[] = $expectedNextStepKey;
            $actualNextStepKey = $actualStepEntries[$position + 1]['step'] ?? null;
            if ($actualNextStepKey !== null && $this->sameSatisfiedAnyOrderParallelGroup($expectedNextStepKey, $actualNextStepKey, $parallelGroups, $actualSteps)) {
                continue;
            }

            if ($actualNextStepKey !== $expectedNextStepKey) {
                $deviations[] = sprintf(
                    'Decision rule violation: %s after %s expected %s but got %s. Context: %s. Rule: %s',
                    $decisionPoint->key,
                    $decisionPoint->after,
                    $expectedNextStepKey,
                    $actualNextStepKey ?? 'none',
                    $this->formatDecisionContext($decisionPoint, $context),
                    $this->formatDecisionRule($matchedRule)
                );
            }
        }

        return $deviations;
    }

    /**
     * @param array<int, ProcessTemplateParallelGroup> $parallelGroups
     * @param array<int, string> $actualSteps
     */
    private function sameSatisfiedAnyOrderParallelGroup(string $expectedStepKey, string $actualStepKey, array $parallelGroups, array $actualSteps): bool
    {
        foreach ($parallelGroups as $group) {
            if (!in_array($expectedStepKey, $group->requiredStepKeys, true) || !in_array($actualStepKey, $group->requiredStepKeys, true)) {
                continue;
            }

            if (array_diff($group->requiredStepKeys, $actualSteps) !== []) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function formatDecisionContext(ProcessTemplateDecisionPoint $decisionPoint, array $context): string
    {
        if ($decisionPoint->requiredFields === []) {
            return '(none)';
        }

        $values = [];
        foreach ($decisionPoint->requiredFields as $field) {
            $values[] = sprintf(
                '%s=%s',
                $field,
                array_key_exists($field, $context) ? $this->formatContextValue($context[$field]) : '<missing>'
            );
        }

        return implode(', ', $values);
    }

    private function formatDecisionRule(ProcessTemplateDecisionRule $rule): string
    {
        if ($rule->isElse || $rule->condition === null) {
            return 'else';
        }

        return sprintf(
            'when %s %s %s',
            $rule->condition->field,
            $rule->condition->operator,
            $this->formatContextValue($rule->condition->value)
        );
    }

    private function formatContextValue(mixed $value): string
    {
        if ($value === null) {
            return '<missing>';
        }

        if (is_string($value)) {
            return sprintf('"%s"', $value);
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return floor($value) === $value
                ? sprintf('%.1F', $value)
                : rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return sprintf('[%s]', implode(', ', array_map(
                fn (mixed $item): string => $this->formatContextValue($item),
                $value
            )));
        }

        return (string) $value;
    }

    /**
     * @param array<int, array{step: string, context: array<string, mixed>|null}> $actualStepEntries
     * @param array<int, string> $requiredFields
     * @return array<string, mixed>|null
     */
    private function contextForDecisionPoint(array $actualStepEntries, int $position, array $requiredFields): ?array
    {
        $context = $actualStepEntries[$position]['context'] ?? null;
        if ($this->contextContainsFields($context, $requiredFields)) {
            return $context;
        }

        $maxDistance = max($position, count($actualStepEntries) - $position - 1);
        for ($distance = 1; $distance <= $maxDistance; ++$distance) {
            $previous = $actualStepEntries[$position - $distance]['context'] ?? null;
            if ($this->contextContainsFields($previous, $requiredFields)) {
                return $previous;
            }

            $next = $actualStepEntries[$position + $distance]['context'] ?? null;
            if ($this->contextContainsFields($next, $requiredFields)) {
                return $next;
            }
        }

        return $context;
    }

    /**
     * @param array<string, mixed>|null $context
     * @param array<int, string> $fields
     */
    private function contextContainsFields(?array $context, array $fields): bool
    {
        if ($context === null) {
            return false;
        }

        foreach ($fields as $field) {
            if (!array_key_exists($field, $context) || !$this->hasContextValue($context[$field])) {
                return false;
            }
        }

        return true;
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
            fn (string $field): bool => !array_key_exists($field, $context) || !$this->hasContextValue($context[$field])
        ));
    }

    private function hasContextValue(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        return !is_array($value) || $value !== [];
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
