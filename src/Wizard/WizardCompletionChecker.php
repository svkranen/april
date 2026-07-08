<?php

namespace App\Wizard;

final readonly class WizardCompletionChecker
{
    /**
     * @return array<int, WizardCompletionCheckResult>
     */
    public function checkStep(WizardStepDefinition $step): array
    {
        $completion = $step->data['completion'] ?? null;
        if (!is_array($completion)) {
            return [
                new WizardCompletionCheckResult(
                    $step->key,
                    'none',
                    WizardCompletionCheckResult::STATUS_UNKNOWN,
                    'No completion rule is defined for this step.'
                ),
            ];
        }

        if ($this->isList($completion)) {
            $results = [];
            foreach ($completion as $rule) {
                if (is_array($rule)) {
                    $results[] = $this->checkRule($step->key, $rule);
                }
            }

            return $results !== [] ? $results : [
                new WizardCompletionCheckResult(
                    $step->key,
                    'none',
                    WizardCompletionCheckResult::STATUS_UNKNOWN,
                    'No completion rule is defined for this step.'
                ),
            ];
        }

        return [$this->checkRule($step->key, $completion)];
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function checkRule(string $stepKey, array $rule): WizardCompletionCheckResult
    {
        $type = $this->stringValue($rule['type'] ?? null, 'unknown');

        return match ($type) {
            'route_visited' => new WizardCompletionCheckResult(
                $stepKey,
                $type,
                WizardCompletionCheckResult::STATUS_UNKNOWN,
                'Route visits are not tracked yet.'
            ),
            'step_acknowledged' => new WizardCompletionCheckResult(
                $stepKey,
                $type,
                WizardCompletionCheckResult::STATUS_UNKNOWN,
                'No Wizard runtime or persistence exists yet.'
            ),
            'manual' => new WizardCompletionCheckResult(
                $stepKey,
                $type,
                WizardCompletionCheckResult::STATUS_UNKNOWN,
                'Manual completion is not executable in the MVP.'
            ),
            default => new WizardCompletionCheckResult(
                $stepKey,
                $type,
                WizardCompletionCheckResult::STATUS_WARNING,
                sprintf('Unsupported completion type "%s".', $type)
            ),
        };
    }

    /**
     * @param array<mixed> $value
     */
    private function isList(array $value): bool
    {
        return array_is_list($value);
    }

    private function stringValue(mixed $value, string $default = ''): string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : $default;
    }
}
