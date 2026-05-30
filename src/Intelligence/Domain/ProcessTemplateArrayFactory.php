<?php

namespace App\Intelligence\Domain;

final class ProcessTemplateArrayFactory
{
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
            self::steps($data['steps'] ?? []),
            self::transitions($data['transitions'] ?? $data['allowed_transitions'] ?? []),
            self::parallelGroups($data['parallel_groups'] ?? []),
            self::contextProfileRequiredFields($data['context_profile'] ?? [])
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
