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
        return new ProcessTemplate(
            self::stringValue($data['key'] ?? '', ''),
            self::stringValue($data['version'] ?? 'draft', 'draft'),
            self::nullableString($data['name'] ?? null),
            self::nullableString($data['initial_step'] ?? $data['initialStepKey'] ?? null),
            steps: self::steps($data['steps'] ?? []),
            transitions: self::transitions($data['transitions'] ?? $data['allowed_transitions'] ?? []),
            parallelGroups: self::parallelGroups($data['parallel_groups'] ?? []),
            contextProfileRequiredFields: self::contextProfileRequiredFields($data['context_profile'] ?? []),
            fieldMappings: self::fieldMappings($data['field_mapping'] ?? []),
            decisionPoints: self::decisionPoints($data['decision_points'] ?? []),
            requiredStepKeys: self::stringList($data['required_steps'] ?? []),
            connector: self::connector($data['connector'] ?? null),
            contextPolicy: self::contextPolicy($data['context_policy'] ?? null)
        );
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
                self::stringValue($step['type'] ?? 'normal', 'normal')
            );
        }

        return $result;
    }

    /**
     * @return array<int, ProcessTemplateTransition>
     */
    private static function transitions(mixed $transitions): array
    {
        if (!is_array($transitions)) {
            return [];
        }

        $result = [];
        foreach ($transitions as $transition) {
            if (!is_array($transition) || !isset($transition['from'], $transition['to']) || !is_scalar($transition['from']) || !is_scalar($transition['to'])) {
                continue;
            }

            $from = trim((string) $transition['from']);
            $to = trim((string) $transition['to']);
            if ($from === '' || $to === '') {
                continue;
            }

            $result[] = new ProcessTemplateTransition($from, $to);
        }

        return $result;
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
                self::stringValue($group['order'] ?? 'any', 'any')
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
    private static function decisionPoints(mixed $decisionPoints): array
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

            $rules = self::decisionRules($decisionPoint['rules'] ?? []);
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
    private static function decisionRules(mixed $rules): array
    {
        if (!is_array($rules)) {
            return [];
        }

        $result = [];
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            if (array_key_exists('else', $rule)) {
                $expectedNextStepKey = self::nullableString(
                    is_array($rule['else'] ?? null)
                        ? ($rule['else']['expect_next'] ?? null)
                        : ($rule['expect_next'] ?? null)
                );
                if ($expectedNextStepKey === null) {
                    continue;
                }

                $result[] = new ProcessTemplateDecisionRule(null, $expectedNextStepKey, true);
                continue;
            }

            $expectedNextStepKey = self::nullableString($rule['expect_next'] ?? null);
            if ($expectedNextStepKey === null) {
                continue;
            }

            $condition = self::decisionCondition($rule['when'] ?? null);
            if ($condition === null) {
                continue;
            }

            $result[] = new ProcessTemplateDecisionRule($condition, $expectedNextStepKey);
        }

        return $result;
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
}
