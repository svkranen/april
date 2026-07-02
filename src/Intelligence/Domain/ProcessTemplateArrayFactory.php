<?php

namespace App\Intelligence\Domain;

use InvalidArgumentException;

final class ProcessTemplateArrayFactory
{
    private const SUPPORTED_DECISION_OPERATORS = [
        'eq',
        'neq',
        'gt',
        'gte',
        'lt',
        'lte',
        'in',
        'exists',
    ];

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): ProcessTemplate
    {
        $sourceSystem = self::sourceSystem($data, 'amagno');
        $steps = self::steps($data['steps'] ?? []);
        $parallelGroups = self::parallelGroups($data['parallel_groups'] ?? []);

        return new ProcessTemplate(
            self::stringValue($data['key'] ?? '', ''),
            self::stringValue($data['version'] ?? 'draft', 'draft'),
            self::nullableString($data['name'] ?? null),
            self::nullableString($data['initial_step'] ?? $data['initialStepKey'] ?? null),
            steps: $steps,
            transitions: self::transitions($data['transitions'] ?? $data['allowed_transitions'] ?? [], $steps, $parallelGroups),
            parallelGroups: $parallelGroups,
            contextProfileRequiredFields: self::contextProfileRequiredFields($data['context_profile'] ?? []),
            fieldMappings: self::fieldMappings($data['field_mapping'] ?? []),
            decisionPoints: self::decisionPoints($data['decision_points'] ?? [], $steps, $parallelGroups),
            signChecks: self::signChecks($data['sign_checks'] ?? [], $data['checks'] ?? []),
            requiredStepKeys: self::stringList($data['required_steps'] ?? []),
            connector: self::connector($data['connector'] ?? null),
            contextPolicy: self::contextPolicy($data['context_policy'] ?? null),
            accessProbes: self::accessProbes($data['access_probes'] ?? [], $sourceSystem),
            visibilityProfiles: self::visibilityProfiles($data['visibility_check_profiles'] ?? []),
            visibilityProfileResolvers: self::visibilityProfileResolvers($data['visibility_profile_resolvers'] ?? []),
            visibilityRetryPolicies: self::visibilityRetryPolicies($data['visibility_retry_policies'] ?? []),
            manualAccessTests: self::manualAccessTests($data['manual_access_tests'] ?? []),
            crossProcessRoutingRules: self::crossProcessRoutingRules($data['cross_process_routing'] ?? []),
            sourceSystem: $sourceSystem
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function sourceSystem(array $data, string $default): string
    {
        return self::stringValue($data['source_system'] ?? $data['sourceSystem'] ?? null, $default);
    }

    private static function contextPolicy(mixed $contextPolicy): ?ProcessTemplateContextPolicy
    {
        if (!is_array($contextPolicy)) {
            return null;
        }

        $snapshot = $contextPolicy['snapshot'] ?? null;
        if (!is_array($snapshot)) {
            return null;
        }

        $maxDelaySeconds = null;
        if (isset($snapshot['max_delay_seconds']) && is_scalar($snapshot['max_delay_seconds'])) {
            $maxDelaySeconds = max(0, (int) $snapshot['max_delay_seconds']);
        }

        return new ProcessTemplateContextPolicy(
            $maxDelaySeconds,
            self::stringValue($snapshot['stale_behavior'] ?? 'uncertain', 'uncertain')
        );
    }

    /**
     * @return array<int, ProcessTemplateCrossProcessRoutingRule>
     */
    private static function crossProcessRoutingRules(mixed $rules): array
    {
        if (!is_array($rules)) {
            return [];
        }

        $result = [];
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $key = self::nullableString($rule['key'] ?? null);
            $afterStep = self::nullableString($rule['after_step'] ?? $rule['afterStep'] ?? null);
            $expectedProcess = self::nullableString($rule['expected_process'] ?? $rule['expectedProcess'] ?? null);
            if ($key === null || $afterStep === null || $expectedProcess === null) {
                continue;
            }

            $when = [];
            if (is_array($rule['when'] ?? null)) {
                foreach ($rule['when'] as $field => $value) {
                    if (!is_scalar($field)) {
                        continue;
                    }

                    $field = trim((string) $field);
                    if ($field !== '') {
                        $when[$field] = $value;
                    }
                }
            }

            if ($when === []) {
                continue;
            }

            $result[] = new ProcessTemplateCrossProcessRoutingRule($key, $afterStep, $when, $expectedProcess);
        }

        return $result;
    }

    private static function connector(mixed $connector): ?ProcessTemplateConnector
    {
        if (!is_array($connector)) {
            return null;
        }

        $type = self::nullableString($connector['type'] ?? null);
        if ($type === null) {
            return null;
        }

        return new ProcessTemplateConnector(
            $type,
            self::nullableString($connector['connection'] ?? null)
        );
    }

    /**
     * @return array<int, ProcessTemplateStep>
     */
    private static function steps(mixed $steps): array
    {
        if (!is_array($steps)) {
            return [];
        }

        $result = [];
        foreach ($steps as $step) {
            if (!is_array($step) || !isset($step['key']) || !is_scalar($step['key'])) {
                continue;
            }

            $key = trim((string) $step['key']);
            if ($key === '') {
                continue;
            }

            $result[] = new ProcessTemplateStep(
                $key,
                self::nullableString($step['name'] ?? null),
                self::stringValue($step['type'] ?? 'normal', 'normal'),
                self::visibilityChecksForPhase($step['before'] ?? null, 'before'),
                self::visibilityChecksForPhase($step['after'] ?? null, 'after')
            );
        }

        return $result;
    }

    /**
     * @return array<string, ProcessTemplateAccessProbe>
     */
    private static function accessProbes(mixed $probes, string $templateSourceSystem): array
    {
        if (!is_array($probes)) {
            return [];
        }

        $result = [];
        foreach ($probes as $probeKey => $probe) {
            if (!is_scalar($probeKey) || !is_array($probe)) {
                continue;
            }

            $key = trim((string) $probeKey);
            $type = self::nullableString($probe['type'] ?? null);
            if ($key === '' || $type === null) {
                continue;
            }

            $sourceSystem = self::sourceSystem($probe, $templateSourceSystem);
            $maxDocuments = null;
            if (isset($probe['max_documents']) && is_numeric($probe['max_documents'])) {
                $maxDocuments = max(0, (int) $probe['max_documents']);
            }

            $options = $probe;
            unset(
                $options['source_system'],
                $options['sourceSystem'],
                $options['type'],
                $options['max_documents'],
                $options['description']
            );

            $result[$key] = new ProcessTemplateAccessProbe(
                $key,
                $sourceSystem,
                $type,
                $options,
                $maxDocuments,
                self::nullableString($probe['description'] ?? null)
            );
        }

        return $result;
    }

    /**
     * @return array<string, ProcessTemplateVisibilityProfile>
     */
    private static function visibilityProfiles(mixed $profiles): array
    {
        if (!is_array($profiles)) {
            return [];
        }

        $result = [];
        foreach ($profiles as $profileKey => $profile) {
            if (!is_scalar($profileKey) || !is_array($profile)) {
                continue;
            }

            $key = trim((string) $profileKey);
            if ($key === '') {
                continue;
            }

            $result[$key] = new ProcessTemplateVisibilityProfile(
                $key,
                self::stringList($profile['expected_visible_in_probes'] ?? $profile['expected_visible_in'] ?? []),
                self::stringList($profile['expected_not_visible_in_probes'] ?? $profile['expected_not_visible_in'] ?? [])
            );
        }

        return $result;
    }

    /**
     * @return array<string, ProcessTemplateVisibilityProfileResolver>
     */
    private static function visibilityProfileResolvers(mixed $resolvers): array
    {
        if (!is_array($resolvers)) {
            return [];
        }

        $result = [];
        foreach ($resolvers as $resolverKey => $resolver) {
            if (!is_scalar($resolverKey) || !is_array($resolver)) {
                continue;
            }

            $key = trim((string) $resolverKey);
            $field = self::nullableString($resolver['field'] ?? null);
            if ($key === '' || $field === null) {
                continue;
            }

            $map = [];
            if (is_array($resolver['map'] ?? null)) {
                foreach ($resolver['map'] as $contextValue => $profileKey) {
                    if (!is_scalar($contextValue) || !is_scalar($profileKey)) {
                        continue;
                    }

                    $contextValue = trim((string) $contextValue);
                    $profileKey = trim((string) $profileKey);
                    if ($contextValue !== '' && $profileKey !== '') {
                        $map[$contextValue] = $profileKey;
                    }
                }
            }

            $result[$key] = new ProcessTemplateVisibilityProfileResolver($key, $field, $map);
        }

        return $result;
    }

    /**
     * @return array<string, ProcessTemplateVisibilityRetryPolicy>
     */
    private static function visibilityRetryPolicies(mixed $policies): array
    {
        if (!is_array($policies)) {
            return [];
        }

        $result = [];
        foreach ($policies as $policyKey => $policy) {
            if (!is_scalar($policyKey) || !is_array($policy)) {
                continue;
            }

            $key = trim((string) $policyKey);
            if ($key === '') {
                continue;
            }

            $result[$key] = new ProcessTemplateVisibilityRetryPolicy(
                $key,
                self::intList($policy['attempts_after_seconds'] ?? []),
                self::stringValue($policy['forbidden_found'] ?? 'violation', 'violation'),
                self::stringValue($policy['expected_missing_after_last_attempt'] ?? 'warning', 'warning'),
                self::stringValue($policy['probe_too_large'] ?? $policy['magnet_too_large'] ?? 'technical_warning', 'technical_warning')
            );
        }

        return $result;
    }

    /**
     * @return array<int, ProcessTemplateManualAccessTest>
     */
    private static function manualAccessTests(mixed $tests): array
    {
        if (!is_array($tests)) {
            return [];
        }

        $result = [];
        foreach ($tests as $test) {
            if (!is_array($test)) {
                continue;
            }

            $key = self::nullableString($test['key'] ?? null);
            if ($key === null) {
                continue;
            }

            $result[] = new ProcessTemplateManualAccessTest(
                $key,
                self::nullableString($test['title'] ?? null),
                self::nullableString($test['description'] ?? null),
                self::stringList($test['test_procedure'] ?? []),
                self::stringList($test['expected_result'] ?? []),
                self::nullableString($test['frequency'] ?? null),
                self::nullableString($test['evidence_required'] ?? $test['evidenceRequired'] ?? null)
            );
        }

        return $result;
    }

    /**
     * @return array<int, ProcessTemplateVisibilityCheck>
     */
    private static function visibilityChecksForPhase(mixed $phaseConfig, string $phase): array
    {
        if (!is_array($phaseConfig) || !is_array($phaseConfig['visibility_checks'] ?? null)) {
            return [];
        }

        $result = [];
        foreach ($phaseConfig['visibility_checks'] as $check) {
            if (!is_array($check)) {
                continue;
            }

            $key = self::nullableString($check['key'] ?? null);
            if ($key === null) {
                continue;
            }

            $result[] = new ProcessTemplateVisibilityCheck(
                $key,
                $phase,
                self::nullableString($check['expected_profile'] ?? null),
                self::nullableString($check['expected_profile_resolver'] ?? null),
                self::nullableString($check['retry_policy'] ?? null),
                self::nullableString($check['source_system'] ?? $check['sourceSystem'] ?? null)
            );
        }

        return $result;
    }

    /**
     * @param array<int, ProcessTemplateStep> $steps
     * @param array<int, ProcessTemplateParallelGroup> $parallelGroups
     * @return array<int, ProcessTemplateTransition>
     */
    private static function transitions(mixed $transitions, array $steps, array $parallelGroups): array
    {
        if (!is_array($transitions)) {
            return [];
        }

        $stepKeys = array_fill_keys(array_map(static fn (ProcessTemplateStep $step): string => $step->key, $steps), true);
        $parallelGroupKeys = array_fill_keys(array_map(static fn (ProcessTemplateParallelGroup $group): string => $group->key, $parallelGroups), true);
        $result = [];
        foreach ($transitions as $transition) {
            if (!is_array($transition) || !isset($transition['from']) || !is_scalar($transition['from'])) {
                continue;
            }

            $from = trim((string) $transition['from']);
            $to = self::nullableString($transition['to'] ?? null);
            $toParallelGroup = self::nullableString($transition['to_parallel_group'] ?? null);
            if ($from === '') {
                continue;
            }

            if (($to === null) === ($toParallelGroup === null)) {
                throw new InvalidArgumentException(sprintf('Transition from "%s" must define exactly one of "to" or "to_parallel_group".', $from));
            }

            if (!isset($stepKeys[$from])) {
                throw new InvalidArgumentException(sprintf('Transition from "%s" references unknown step.', $from));
            }

            if ($to !== null && !isset($stepKeys[$to])) {
                throw new InvalidArgumentException(sprintf('Transition from "%s" references unknown target step "%s".', $from, $to));
            }

            if ($toParallelGroup !== null && !isset($parallelGroupKeys[$toParallelGroup])) {
                throw new InvalidArgumentException(sprintf('Transition from "%s" references unknown parallel group "%s".', $from, $toParallelGroup));
            }

            $result[] = new ProcessTemplateTransition($from, $to, $toParallelGroup);
        }

        self::assertNoRedundantAnyOrderParallelGroupTransitions($result, $parallelGroups);

        return $result;
    }

    /**
     * @param array<int, ProcessTemplateTransition> $transitions
     * @param array<int, ProcessTemplateParallelGroup> $parallelGroups
     */
    private static function assertNoRedundantAnyOrderParallelGroupTransitions(array $transitions, array $parallelGroups): void
    {
        $parallelGroupsByKey = [];
        foreach ($parallelGroups as $parallelGroup) {
            $parallelGroupsByKey[$parallelGroup->key] = $parallelGroup;
        }

        $directTargetsByFrom = [];
        $parallelTargetsByFrom = [];
        foreach ($transitions as $transition) {
            if ($transition->to !== null) {
                $directTargetsByFrom[$transition->from][$transition->to] = true;
            }
            if ($transition->toParallelGroup !== null) {
                $parallelTargetsByFrom[$transition->from][] = $transition->toParallelGroup;
            }
        }

        foreach ($parallelTargetsByFrom as $from => $parallelGroupKeys) {
            foreach ($parallelGroupKeys as $parallelGroupKey) {
                $parallelGroup = $parallelGroupsByKey[$parallelGroupKey] ?? null;
                if (!$parallelGroup instanceof ProcessTemplateParallelGroup || $parallelGroup->order !== 'any') {
                    continue;
                }

                foreach ($parallelGroup->requiredStepKeys as $requiredStepKey) {
                    if (isset($directTargetsByFrom[$from][$requiredStepKey])) {
                        throw new InvalidArgumentException(sprintf(
                            'Transition from "%s" to "%s" is redundant because "%s" also activates any-order parallel group "%s".',
                            $from,
                            $requiredStepKey,
                            $from,
                            $parallelGroupKey
                        ));
                    }
                }
            }
        }
    }

    /**
     * @return array<int, ProcessTemplateParallelGroup>
     */
    private static function parallelGroups(mixed $groups): array
    {
        if (!is_array($groups)) {
            return [];
        }

        $result = [];
        foreach ($groups as $index => $group) {
            if (!is_array($group)) {
                continue;
            }

            $requiredStepKeys = self::stringList($group['required_steps'] ?? []);
            if ($requiredStepKeys === []) {
                continue;
            }

            $result[] = new ProcessTemplateParallelGroup(
                self::stringValue($group['key'] ?? sprintf('parallel_group_%d', $index), sprintf('parallel_group_%d', $index)),
                self::nullableString($group['after'] ?? null),
                $requiredStepKeys,
                self::stringValue($group['order'] ?? 'any', 'any'),
                self::nullableString($group['next'] ?? null)
            );
        }

        return $result;
    }

    /**
     * @return array<int, string>
     */
    private static function contextProfileRequiredFields(mixed $contextProfile): array
    {
        if (!is_array($contextProfile)) {
            return [];
        }

        return self::stringList($contextProfile['required'] ?? []);
    }

    /**
     * @return array<string, ProcessTemplateFieldMapping>
     */
    private static function fieldMappings(mixed $fieldMappings): array
    {
        if (!is_array($fieldMappings)) {
            return [];
        }

        $result = [];
        foreach ($fieldMappings as $fieldKey => $mapping) {
            if (!is_scalar($fieldKey) || !is_array($mapping)) {
                continue;
            }

            $fieldKey = trim((string) $fieldKey);
            $source = self::nullableString($mapping['source'] ?? null);
            if ($fieldKey === '' || $source === null) {
                continue;
            }

            $result[$fieldKey] = new ProcessTemplateFieldMapping(
                $fieldKey,
                $source,
                self::nullableString($mapping['tag_name'] ?? null),
                self::nullableString($mapping['tag_id'] ?? null),
                self::nullableString($mapping['value_type'] ?? null),
                self::fieldStability($mapping['stability'] ?? null)
            );
        }

        return $result;
    }

    private static function fieldStability(mixed $value): ?string
    {
        $stability = self::nullableString($value);
        if ($stability === null) {
            return null;
        }

        if (!in_array($stability, [
            ProcessTemplateFieldMapping::STABILITY_IMMUTABLE,
            ProcessTemplateFieldMapping::STABILITY_MUTABLE,
            ProcessTemplateFieldMapping::STABILITY_SNAPSHOT_REQUIRED,
        ], true)) {
            throw new InvalidArgumentException(sprintf('Unsupported field stability "%s".', $stability));
        }

        return $stability;
    }

    /**
     * @return array<int, ProcessTemplateDecisionPoint>
     */
    private static function decisionPoints(mixed $decisionPoints, array $steps = [], array $parallelGroups = []): array
    {
        if (!is_array($decisionPoints)) {
            return [];
        }

        $result = [];
        foreach ($decisionPoints as $decisionPoint) {
            if (!is_array($decisionPoint) || !isset($decisionPoint['key']) || !is_scalar($decisionPoint['key'])) {
                continue;
            }

            $key = trim((string) $decisionPoint['key']);
            if ($key === '') {
                continue;
            }

            $rules = self::decisionRules($decisionPoint['rules'] ?? [], $steps, $parallelGroups, $key);
            if ($rules === []) {
                continue;
            }

            $result[] = new ProcessTemplateDecisionPoint(
                $key,
                self::nullableString($decisionPoint['after'] ?? null),
                self::stringList($decisionPoint['required_fields'] ?? []),
                $rules
            );
        }

        return $result;
    }

    /**
     * @return array<int, ProcessTemplateDecisionRule>
     */
    private static function decisionRules(mixed $rules, array $steps = [], array $parallelGroups = [], string $decisionPointKey = ''): array
    {
        if (!is_array($rules)) {
            return [];
        }

        $stepKeys = array_fill_keys(array_map(static fn (ProcessTemplateStep $step): string => $step->key, $steps), true);
        $parallelGroupKeys = array_fill_keys(array_map(static fn (ProcessTemplateParallelGroup $group): string => $group->key, $parallelGroups), true);
        $result = [];
        foreach ($rules as $ruleIndex => $rule) {
            if (!is_array($rule)) {
                continue;
            }

            if (array_key_exists('else', $rule)) {
                [$expectedNextStepKey, $expectedNextParallelGroupKey] = self::decisionRuleTargets(
                    is_array($rule['else'] ?? null) ? $rule['else'] : $rule,
                    $stepKeys,
                    $parallelGroupKeys,
                    $decisionPointKey,
                    $ruleIndex
                );

                $result[] = new ProcessTemplateDecisionRule(null, $expectedNextStepKey, true, $expectedNextParallelGroupKey);
                continue;
            }

            $condition = self::decisionCondition($rule['when'] ?? null);
            if ($condition === null) {
                continue;
            }

            [$expectedNextStepKey, $expectedNextParallelGroupKey] = self::decisionRuleTargets(
                $rule,
                $stepKeys,
                $parallelGroupKeys,
                $decisionPointKey,
                $ruleIndex
            );

            $result[] = new ProcessTemplateDecisionRule($condition, $expectedNextStepKey, false, $expectedNextParallelGroupKey);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $rule
     * @param array<string, true> $stepKeys
     * @param array<string, true> $parallelGroupKeys
     * @return array{0: string|null, 1: string|null}
     */
    private static function decisionRuleTargets(array $rule, array $stepKeys, array $parallelGroupKeys, string $decisionPointKey, int $ruleIndex): array
    {
        $expectedNextStepKey = self::nullableString($rule['expect_next'] ?? null);
        $expectedNextParallelGroupKey = self::nullableString($rule['expect_next_parallel_group'] ?? null);
        $ruleLabel = $decisionPointKey === ''
            ? sprintf('Decision rule #%d', $ruleIndex + 1)
            : sprintf('Decision rule #%d in decision point "%s"', $ruleIndex + 1, $decisionPointKey);

        if (($expectedNextStepKey === null) === ($expectedNextParallelGroupKey === null)) {
            throw new InvalidArgumentException(sprintf('%s must define exactly one of "expect_next" or "expect_next_parallel_group".', $ruleLabel));
        }

        if ($expectedNextStepKey !== null && $stepKeys !== [] && !isset($stepKeys[$expectedNextStepKey])) {
            throw new InvalidArgumentException(sprintf('%s references unknown target step "%s".', $ruleLabel, $expectedNextStepKey));
        }

        if ($expectedNextParallelGroupKey !== null && !isset($parallelGroupKeys[$expectedNextParallelGroupKey])) {
            throw new InvalidArgumentException(sprintf('%s references unknown parallel group "%s".', $ruleLabel, $expectedNextParallelGroupKey));
        }

        return [$expectedNextStepKey, $expectedNextParallelGroupKey];
    }

    private static function decisionCondition(mixed $when): ?ProcessTemplateRuleCondition
    {
        if (!is_array($when)) {
            return null;
        }

        foreach ($when as $field => $operators) {
            if (!is_scalar($field) || !is_array($operators)) {
                continue;
            }

            $field = trim((string) $field);
            if ($field === '') {
                continue;
            }

            foreach ($operators as $operator => $value) {
                if (!is_scalar($operator)) {
                    continue;
                }

                $operator = trim((string) $operator);
                if (!in_array($operator, self::SUPPORTED_DECISION_OPERATORS, true)) {
                    throw new InvalidArgumentException(sprintf('Unsupported decision rule operator "%s".', $operator));
                }

                return new ProcessTemplateRuleCondition($field, $operator, $value);
            }
        }

        return null;
    }

    /**
     * @return array<int, ProcessTemplateSignCheck>
     */
    private static function signChecks(mixed $signChecks, mixed $checks): array
    {
        $result = [];
        foreach ([$signChecks, self::typedSignChecks($checks)] as $definitions) {
            if (!is_array($definitions)) {
                continue;
            }

            foreach ($definitions as $definition) {
                if (!is_array($definition)) {
                    continue;
                }

                $key = self::nullableString($definition['key'] ?? null);
                $requiredSetField = self::contextField($definition['required_set'] ?? null);
                $actualSetField = self::contextField($definition['actual_set'] ?? null);
                $operator = self::stringValue($definition['operator'] ?? ProcessTemplateSignCheck::OPERATOR_REQUIRED_SUBSET_OF_ACTUAL, ProcessTemplateSignCheck::OPERATOR_REQUIRED_SUBSET_OF_ACTUAL);

                if ($key === null || $requiredSetField === null || $actualSetField === null) {
                    continue;
                }

                if ($operator !== ProcessTemplateSignCheck::OPERATOR_REQUIRED_SUBSET_OF_ACTUAL) {
                    throw new InvalidArgumentException(sprintf('Unsupported sign_check operator "%s".', $operator));
                }

                $result[$key] = new ProcessTemplateSignCheck(
                    $key,
                    $requiredSetField,
                    $actualSetField,
                    $operator,
                    self::nullableString($definition['label'] ?? null)
                );
            }
        }

        return array_values($result);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function typedSignChecks(mixed $checks): array
    {
        if (!is_array($checks)) {
            return [];
        }

        return array_values(array_filter(
            $checks,
            static fn (mixed $check): bool => is_array($check) && ($check['type'] ?? null) === 'sign_check'
        ));
    }

    private static function contextField(mixed $value): ?string
    {
        if (is_array($value)) {
            return self::nullableString($value['from_context'] ?? null);
        }

        return self::nullableString($value);
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null || !is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function stringValue(mixed $value, string $default): string
    {
        if (!is_scalar($value)) {
            return $default;
        }

        $value = trim((string) $value);

        return $value === '' ? $default : $value;
    }

    /**
     * @return array<int, string>
     */
    private static function stringList(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }

        $result = [];
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);
            if ($value !== '') {
                $result[] = $value;
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * @return array<int, int>
     */
    private static function intList(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }

        $result = [];
        foreach ($values as $value) {
            if (!is_numeric($value)) {
                continue;
            }

            $result[] = max(0, (int) $value);
        }

        return array_values(array_unique($result));
    }
}
