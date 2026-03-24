<?php

namespace App\SignatureCheck;

final class SignatureCompletenessChecker
{
    /**
     * @param array<int, string> $requiredNames
     * @param array<int, string> $confirmedNames
     */
    public function check(array $requiredNames, array $confirmedNames): SignatureCheckResult
    {
        $required = $this->buildUniqueValues($requiredNames);
        $confirmed = $this->buildUniqueValues($confirmedNames);

        $requiredLookup = $this->buildLookup($required);
        $confirmedLookup = $this->buildLookup($confirmed);

        $missing = [];
        foreach ($requiredLookup as $key => $display) {
            if (!isset($confirmedLookup[$key])) {
                $missing[] = $display;
            }
        }

        $unexpected = [];
        foreach ($confirmedLookup as $key => $display) {
            if (!isset($requiredLookup[$key])) {
                $unexpected[] = $display;
            }
        }

        return new SignatureCheckResult($required, $confirmed, $missing, $unexpected);
    }

    private function sanitize(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param array<int, string> $values
     * @return array<int, string>
     */
    private function buildUniqueValues(array $values): array
    {
        return array_values($this->buildLookup(array_values(array_filter(
            array_map([$this, 'sanitize'], $values),
            static fn (?string $value) => $value !== null
        ))));
    }

    /**
     * @param array<int, string> $values
     * @return array<string, string>
     */
    private function buildLookup(array $values): array
    {
        $lookup = [];

        foreach ($values as $value) {
            $key = mb_strtolower($value);
            if (!isset($lookup[$key])) {
                $lookup[$key] = $value;
            }
        }

        return $lookup;
    }
}
