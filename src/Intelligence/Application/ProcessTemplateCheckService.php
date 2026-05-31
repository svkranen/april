<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateArrayFactory;
use App\Intelligence\Domain\ProcessTemplateDecisionPoint;
use App\Intelligence\Domain\ProcessTemplateDecisionRule;
use App\Intelligence\Domain\ProcessTemplateDecisionRuleEvaluator;
use App\Intelligence\Domain\ProcessTemplateFieldMapping;
use App\Intelligence\Domain\ProcessTemplateParallelGroup;
use App\Intelligence\Domain\ProcessTemplateStep;
use DateTimeImmutable;
use InvalidArgumentException;

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
        $this->assertDecisionFieldsHaveStability($template);

        $parallelGroups = $this->parallelGroups($template);
        $expectedSteps = $this->expectedSteps($template);
        $knownSteps = $this->knownSteps($template, $parallelGroups, $expectedSteps);
        $actualStepEntries = $this->actualStepEntries($documentUuid, $processKey, $documentVersion, $order);
        $actualSteps = array_map(
            static fn (array $entry): string => $entry['step'],
            $actualStepEntries
        );
        $decisionCheck = $this->decisionDeviations($template, $actualStepEntries, $parallelGroups, $actualSteps);
        $deviations = $this->deviations(
            $expectedSteps,
            $actualSteps,
            $parallelGroups,
            $knownSteps,
            $template->transitions,
            $decisionCheck['activated_parallel_groups']
        );
        $deviations = array_merge($deviations, $decisionCheck['deviations']);
        $parallelGroupMessages = $this->parallelGroupMessages(
            $parallelGroups,
            $actualSteps,
            $template->transitions,
            $decisionCheck['activated_parallel_groups']
        );

        return new ProcessTemplateCheckResult(
            $expectedSteps,
            $actualSteps,
            $deviations,
            $parallelGroupMessages,
            $decisionCheck['context_issues'],
            $decisionCheck['context_status']
        );
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
            if ($group->nextStepKey !== null) {
                $knownSteps[] = $group->nextStepKey;
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
     * @return array<int, array{step: string, context: array<string, mixed>|null, context_summary: array<string, mixed>|null, occurred_at: DateTimeImmutable}>
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
                    $entries[$lastIndex]['context_summary'] = $event->contextSummary ?? $entries[$lastIndex]['context_summary'];
                }

                continue;
            }

            $entries[] = [
                'step' => $event->stepKey,
                'context' => $context,
                'context_summary' => $event->contextSummary,
                'occurred_at' => $event->occurredAt,
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
     * @param array<int, array{step: string, context: array<string, mixed>|null, context_summary: array<string, mixed>|null, occurred_at: DateTimeImmutable}> $actualStepEntries
     * @param array<int, ProcessTemplateParallelGroup> $parallelGroups
     * @param array<int, string> $actualSteps
     * @return array{deviations: array<int, string>, context_issues: array<int, string>, context_status: string|null, activated_parallel_groups: array<int, string>}
     */
    private function decisionDeviations(ProcessTemplate $template, array $actualStepEntries, array $parallelGroups, array $actualSteps): array
    {
        $deviations = [];
        $contextIssues = [];
        $contextStatus = null;
        $activatedParallelGroups = [];
        if ($template->decisionPoints === []) {
            return ['deviations' => $deviations, 'context_issues' => $contextIssues, 'context_status' => $contextStatus, 'activated_parallel_groups' => $activatedParallelGroups];
        }

        $expectedDecisionStepKeys = [];
        foreach ($template->decisionPoints as $decisionPoint) {
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

            $requiredFields = $this->decisionFields($decisionPoint);
            $contextIssue = $this->contextIssueForDecisionPoint($template, $decisionPoint, $actualStepEntries[$position]);
            if ($contextIssue !== null) {
                $contextIssues[] = $contextIssue['message'];
                $contextStatus = $this->contextStatusPriority($contextStatus, $contextIssue['status']);
                continue;
            }

            $context = $this->contextForDecisionPoint($actualStepEntries, $position, $requiredFields);
            $missingContextFields = $this->missingContextFields($requiredFields, $context);
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

            if ($matchedRule->expectedNextParallelGroupKey !== null) {
                $expectedParallelGroup = $this->parallelGroupByKey($parallelGroups, $matchedRule->expectedNextParallelGroupKey);
                $activatedParallelGroups[] = $matchedRule->expectedNextParallelGroupKey;
                $actualNextStepKey = $actualStepEntries[$position + 1]['step'] ?? null;

                if ($expectedParallelGroup !== null && $actualNextStepKey !== null && in_array($actualNextStepKey, $expectedParallelGroup->requiredStepKeys, true)) {
                    continue;
                }

                $deviations[] = sprintf(
                    'Decision rule violation: %s after %s expected parallel group %s but got %s. Context: %s. Rule: %s',
                    $decisionPoint->key,
                    $decisionPoint->after,
                    $matchedRule->expectedNextParallelGroupKey,
                    $actualNextStepKey ?? 'none',
                    $this->formatDecisionContext($decisionPoint, $context),
                    $this->formatDecisionRule($matchedRule)
                );
                continue;
            }

            $expectedNextStepKey = $matchedRule->expectedNextStepKey;
            if ($expectedNextStepKey === null) {
                continue;
            }

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

        return [
            'deviations' => $deviations,
            'context_issues' => $contextIssues,
            'context_status' => $contextStatus,
            'activated_parallel_groups' => array_values(array_unique($activatedParallelGroups)),
        ];
    }

    /**
     * @param array<int, ProcessTemplateParallelGroup> $parallelGroups
     */
    private function parallelGroupByKey(array $parallelGroups, string $key): ?ProcessTemplateParallelGroup
    {
        foreach ($parallelGroups as $group) {
            if ($group->key === $key) {
                return $group;
            }
        }

        return null;
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

    private function assertDecisionFieldsHaveStability(ProcessTemplate $template): void
    {
        foreach ($template->decisionPoints as $decisionPoint) {
            foreach ($this->decisionFields($decisionPoint) as $field) {
                $mapping = $template->fieldMappings[$field] ?? null;
                if (!$mapping instanceof ProcessTemplateFieldMapping) {
                    throw new InvalidArgumentException(sprintf(
                        'Missing field_mapping for decision field "%s" in decision point "%s".',
                        $field,
                        $decisionPoint->key
                    ));
                }

                if ($mapping->stability === null) {
                    throw new InvalidArgumentException(sprintf(
                        'Missing stability for decision field "%s" in decision point "%s".',
                        $field,
                        $decisionPoint->key
                    ));
                }
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function decisionFields(ProcessTemplateDecisionPoint $decisionPoint): array
    {
        $fields = $decisionPoint->requiredFields;
        foreach ($decisionPoint->rules as $rule) {
            if ($rule->condition !== null) {
                $fields[] = $rule->condition->field;
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * @param array{step: string, context: array<string, mixed>|null, context_summary: array<string, mixed>|null, occurred_at: DateTimeImmutable} $decisionEntry
     * @return array{status: string, message: string}|null
     */
    private function contextIssueForDecisionPoint(ProcessTemplate $template, ProcessTemplateDecisionPoint $decisionPoint, array $decisionEntry): ?array
    {
        $contextSummary = $decisionEntry['context_summary'] ?? [];
        $attributes = is_array($contextSummary['attributes'] ?? null) ? $contextSummary['attributes'] : [];
        $snapshotLoadedAt = $this->dateTimeFromSummary($contextSummary['loaded_at'] ?? null);
        $snapshotOccurredAt = $this->dateTimeFromSummary($contextSummary['occurred_at'] ?? null) ?? $decisionEntry['occurred_at'];
        $hasSnapshot = ($contextSummary['source'] ?? null) === 'snapshot';
        $maxDelaySeconds = $template->contextPolicy?->snapshotMaxDelaySeconds;
        $storedFreshnessSeconds = $this->intFromSummary($contextSummary['freshness_seconds'] ?? null);
        $calculatedFreshnessSeconds = $snapshotLoadedAt !== null
            ? $snapshotLoadedAt->getTimestamp() - $snapshotOccurredAt->getTimestamp()
            : null;
        $freshnessSeconds = $calculatedFreshnessSeconds ?? $storedFreshnessSeconds;

        foreach ($this->decisionFields($decisionPoint) as $field) {
            $mapping = $template->fieldMappings[$field] ?? null;
            if (!$mapping instanceof ProcessTemplateFieldMapping || $mapping->isImmutable()) {
                continue;
            }

            if (!$hasSnapshot || !array_key_exists($field, $attributes)) {
                return [
                    'status' => 'UNCHECKABLE_CONTEXT_MISSING',
                    'message' => sprintf(
                        'Uncheckable context missing: decision point %s field %s requires a snapshot. event occurred_at=%s',
                        $decisionPoint->key,
                        $field,
                        $snapshotOccurredAt->format(DATE_ATOM)
                    ),
                ];
            }

            if ($snapshotLoadedAt === null) {
                return [
                    'status' => 'UNCHECKABLE_CONTEXT_MISSING',
                    'message' => sprintf(
                        'Uncheckable context missing: decision point %s field %s snapshot has no loaded_at timestamp. event occurred_at=%s',
                        $decisionPoint->key,
                        $field,
                        $snapshotOccurredAt->format(DATE_ATOM)
                    ),
                ];
            }

            if ($freshnessSeconds !== null && $freshnessSeconds < 0) {
                return [
                    'status' => 'UNCERTAIN_CONTEXT_TIME_SKEW',
                    'message' => sprintf(
                        'Uncertain context time skew: decision point %s field %s snapshot freshness_seconds=%d is negative. event occurred_at=%s loaded_at=%s',
                        $decisionPoint->key,
                        $field,
                        $freshnessSeconds,
                        $snapshotOccurredAt->format(DATE_ATOM),
                        $snapshotLoadedAt->format(DATE_ATOM)
                    ),
                ];
            }

            if ($maxDelaySeconds !== null && $freshnessSeconds !== null && $freshnessSeconds > $maxDelaySeconds) {
                return [
                    'status' => 'UNCERTAIN_CONTEXT_STALE',
                    'message' => sprintf(
                        'Uncertain context stale: decision point %s field %s snapshot freshness_seconds=%d exceeds max_delay_seconds=%d. event occurred_at=%s loaded_at=%s',
                        $decisionPoint->key,
                        $field,
                        $freshnessSeconds,
                        $maxDelaySeconds,
                        $snapshotOccurredAt->format(DATE_ATOM),
                        $snapshotLoadedAt->format(DATE_ATOM)
                    ),
                ];
            }
        }

        return null;
    }

    private function contextStatusPriority(?string $currentStatus, string $newStatus): string
    {
        if ($currentStatus === 'UNCHECKABLE_CONTEXT_MISSING' || $newStatus === 'UNCHECKABLE_CONTEXT_MISSING') {
            return 'UNCHECKABLE_CONTEXT_MISSING';
        }

        if ($currentStatus === 'UNCERTAIN_CONTEXT_TIME_SKEW' || $newStatus === 'UNCERTAIN_CONTEXT_TIME_SKEW') {
            return 'UNCERTAIN_CONTEXT_TIME_SKEW';
        }

        return $newStatus;
    }

    private function dateTimeFromSummary(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if (!is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        return new DateTimeImmutable((string) $value);
    }

    private function intFromSummary(mixed $value): ?int
    {
        if (!is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        return (int) $value;
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
     * @param array<int, array{step: string, context: array<string, mixed>|null, context_summary: array<string, mixed>|null, occurred_at: DateTimeImmutable}> $actualStepEntries
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
    private function missingContextFields(array $requiredFields, ?array $context): array
    {
        if ($requiredFields === []) {
            return [];
        }

        if ($context === null) {
            return $requiredFields;
        }

        return array_values(array_filter(
            $requiredFields,
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
     * @param array<int, \App\Intelligence\Domain\ProcessTemplateTransition> $transitions
     * @return array<int, string>
     */
    private function deviations(array $expectedSteps, array $actualSteps, array $parallelGroups, array $knownSteps, array $transitions, array $activatedParallelGroups = []): array
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

        foreach ($this->transitionViolations($actualSteps, $parallelGroups, $transitions) as $transitionViolation) {
            $deviations[] = $transitionViolation;
        }

        foreach ($parallelGroups as $group) {
            if (!$this->isParallelGroupActivated($group, $transitions, $actualSteps, $activatedParallelGroups)) {
                continue;
            }

            $missingRequiredSteps = array_values(array_diff($group->requiredStepKeys, $actualSteps));
            if ($missingRequiredSteps !== []) {
                $deviations[] = sprintf(
                    'Parallel Group incomplete: %s (missing: %s)',
                    $group->key,
                    implode(', ', $missingRequiredSteps)
                );

                continue;
            }

            if ($group->nextStepKey !== null && !in_array($group->nextStepKey, $actualSteps, true)) {
                $deviations[] = sprintf(
                    'Missing next after parallel group: %s -> %s',
                    $group->key,
                    $group->nextStepKey
                );
            }
        }

        return $deviations;
    }

    /**
     * @param array<int, string> $actualSteps
     * @param array<int, ProcessTemplateParallelGroup> $parallelGroups
     * @param array<int, \App\Intelligence\Domain\ProcessTemplateTransition> $transitions
     * @return array<int, string>
     */
    private function transitionViolations(array $actualSteps, array $parallelGroups, array $transitions): array
    {
        if ($transitions === []) {
            return [];
        }

        $allowedTargetsByFrom = [];
        foreach ($transitions as $transition) {
            if ($transition->to !== null) {
                $allowedTargetsByFrom[$transition->from][] = $transition->to;
                continue;
            }

            if ($transition->toParallelGroup !== null) {
                $allowedTargetsByFrom[$transition->from] = array_merge(
                    $allowedTargetsByFrom[$transition->from] ?? [],
                    $this->requiredStepsForParallelGroup($parallelGroups, $transition->toParallelGroup)
                );
            }
        }

        $violations = [];
        for ($index = 0, $max = count($actualSteps) - 1; $index < $max; ++$index) {
            $from = $actualSteps[$index];
            $actualNext = $actualSteps[$index + 1];
            $allowedTargets = array_values(array_unique($allowedTargetsByFrom[$from] ?? []));
            if ($allowedTargets === [] || in_array($actualNext, $allowedTargets, true)) {
                continue;
            }

            $violations[] = sprintf(
                'Transition violation: %s expected one of %s but got %s',
                $from,
                implode(', ', $allowedTargets),
                $actualNext
            );
        }

        return $violations;
    }

    /**
     * @param array<int, ProcessTemplateParallelGroup> $parallelGroups
     * @return array<int, string>
     */
    private function requiredStepsForParallelGroup(array $parallelGroups, string $parallelGroupKey): array
    {
        foreach ($parallelGroups as $group) {
            if ($group->key === $parallelGroupKey) {
                return $group->requiredStepKeys;
            }
        }

        return [];
    }

    /**
     * @param array<int, \App\Intelligence\Domain\ProcessTemplateTransition> $transitions
     * @param array<int, string> $actualSteps
     */
    private function isParallelGroupActivated(ProcessTemplateParallelGroup $group, array $transitions, array $actualSteps, array $activatedParallelGroups = []): bool
    {
        if (in_array($group->key, $activatedParallelGroups, true)) {
            return true;
        }

        $activationSteps = [];
        foreach ($transitions as $transition) {
            if ($transition->toParallelGroup === $group->key) {
                $activationSteps[] = $transition->from;
            }
        }

        if ($activationSteps === []) {
            return true;
        }

        foreach ($activationSteps as $activationStep) {
            if (in_array($activationStep, $actualSteps, true)) {
                return true;
            }
        }

        return false;
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
    private function parallelGroupMessages(array $parallelGroups, array $actualSteps, array $transitions = [], array $activatedParallelGroups = []): array
    {
        $messages = [];
        foreach ($parallelGroups as $group) {
            if (!$this->isParallelGroupActivated($group, $transitions, $actualSteps, $activatedParallelGroups)) {
                continue;
            }

            $missingRequiredSteps = array_values(array_diff($group->requiredStepKeys, $actualSteps));
            if ($missingRequiredSteps === []) {
                $messages[] = $group->nextStepKey === null
                    ? sprintf('Parallel Group satisfied: %s', $group->key)
                    : sprintf('Parallel Group satisfied: %s (next: %s)', $group->key, $group->nextStepKey);

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
