<?php

namespace App\Intelligence\Domain;

final class SignCheckEvaluator
{
    /**
     * @param array<string, mixed>|null $context
     */
    public function evaluate(ProcessTemplateSignCheck $check, ?array $context): SignCheckResult
    {
        $missingFields = [];
        if ($context === null || !array_key_exists($check->requiredSetField, $context)) {
            $missingFields[] = $check->requiredSetField;
        }
        if ($context === null || !array_key_exists($check->actualSetField, $context)) {
            $missingFields[] = $check->actualSetField;
        }

        if ($missingFields !== []) {
            return new SignCheckResult($check->key, $check->label, SignCheckResult::STATUS_MISSING_CONTEXT, 0, 0, 0, 0, 0, $missingFields);
        }

        $required = $this->setFromContextValue($context[$check->requiredSetField]);
        $actual = $this->setFromContextValue($context[$check->actualSetField]);
        if ($required === []) {
            return new SignCheckResult($check->key, $check->label, SignCheckResult::STATUS_EMPTY_REQUIRED_SET, 0, count($actual), 0, 0, count($actual));
        }

        $missing = array_values(array_diff($required, $actual));
        $unexpected = array_values(array_diff($actual, $required));
        $matchedCount = count($required) - count($missing);

        if ($missing === []) {
            $status = $unexpected === []
                ? SignCheckResult::STATUS_SATISFIED
                : SignCheckResult::STATUS_UNEXPECTED_SIGNER;

            return new SignCheckResult($check->key, $check->label, $status, count($required), count($actual), $matchedCount, 0, count($unexpected), [], [], $unexpected);
        }

        $status = $matchedCount === 0
            ? SignCheckResult::STATUS_MISSING_ALL
            : SignCheckResult::STATUS_PARTIAL;

        return new SignCheckResult($check->key, $check->label, $status, count($required), count($actual), $matchedCount, count($missing), count($unexpected), [], $missing, $unexpected);
    }

    /**
     * @return array<int, string>
     */
    private function setFromContextValue(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $values = [];
        if (is_array($value)) {
            $values = $this->flattenValues($value);
        } elseif (is_scalar($value)) {
            $decoded = json_decode((string) $value, true);
            if (is_array($decoded)) {
                $values = $this->flattenValues($decoded);
            } else {
                $values = preg_split('/[,;\n]+/', (string) $value) ?: [];
            }
        }

        $normalized = [];
        foreach ($values as $item) {
            if (!is_scalar($item)) {
                continue;
            }

            $string = trim((string) $item);
            if ($string !== '') {
                $normalized[$string] = $string;
            }
        }

        return array_values($normalized);
    }

    /**
     * @param array<mixed> $values
     * @return array<int, mixed>
     */
    private function flattenValues(array $values): array
    {
        $flattened = [];
        foreach ($values as $value) {
            if (is_array($value)) {
                if (isset($value['name']) && is_scalar($value['name'])) {
                    $flattened[] = $value['name'];
                    continue;
                }
                if (isset($value['id']) && is_scalar($value['id'])) {
                    $flattened[] = $value['id'];
                    continue;
                }

                foreach ($this->flattenValues($value) as $nestedValue) {
                    $flattened[] = $nestedValue;
                }
                continue;
            }

            $flattened[] = $value;
        }

        return $flattened;
    }
}
