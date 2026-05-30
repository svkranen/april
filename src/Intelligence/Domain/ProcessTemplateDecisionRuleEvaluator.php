<?php

namespace App\Intelligence\Domain;

final class ProcessTemplateDecisionRuleEvaluator
{
    /**
     * @param array<string, mixed> $context
     */
    public function expectedNextStepKey(ProcessTemplateDecisionPoint $decisionPoint, array $context): ?string
    {
        $elseRule = null;
        foreach ($decisionPoint->rules as $rule) {
            if ($rule->isElse) {
                $elseRule ??= $rule;
                continue;
            }

            if ($rule->condition !== null && $this->matches($rule->condition, $context)) {
                return $rule->expectedNextStepKey;
            }
        }

        return $elseRule?->expectedNextStepKey;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function matches(ProcessTemplateRuleCondition $condition, array $context): bool
    {
        $fieldExists = array_key_exists($condition->field, $context) && $context[$condition->field] !== null;
        $actual = $context[$condition->field] ?? null;

        return match ($condition->operator) {
            'eq' => $fieldExists && $actual == $condition->value,
            'neq' => $fieldExists && $actual != $condition->value,
            'gt' => $fieldExists && $this->compareNumeric($actual, $condition->value, static fn (float $left, float $right): bool => $left > $right),
            'gte' => $fieldExists && $this->compareNumeric($actual, $condition->value, static fn (float $left, float $right): bool => $left >= $right),
            'lt' => $fieldExists && $this->compareNumeric($actual, $condition->value, static fn (float $left, float $right): bool => $left < $right),
            'lte' => $fieldExists && $this->compareNumeric($actual, $condition->value, static fn (float $left, float $right): bool => $left <= $right),
            'in' => $fieldExists && $this->matchesIn($actual, $condition->value),
            'exists' => $this->matchesExists($fieldExists, $condition->value),
            default => false,
        };
    }

    private function compareNumeric(mixed $actual, mixed $expected, callable $compare): bool
    {
        if (!is_numeric($actual) || !is_numeric($expected)) {
            return false;
        }

        return $compare((float) $actual, (float) $expected);
    }

    private function matchesIn(mixed $actual, mixed $expected): bool
    {
        if (!is_array($expected)) {
            return false;
        }

        return in_array($actual, $expected, true);
    }

    private function matchesExists(bool $fieldExists, mixed $expected): bool
    {
        return (bool) $expected === $fieldExists;
    }
}
