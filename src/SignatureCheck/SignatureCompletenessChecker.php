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
        $required = array_values(array_filter(array_map([$this, 'sanitize'], $requiredNames), static fn (?string $value) => $value !== null));
        $confirmed = array_values(array_filter(array_map([$this, 'sanitize'], $confirmedNames), static fn (?string $value) => $value !== null));

        $requiredCounts = $this->buildCounts($required);
        $confirmedCounts = $this->buildCounts($confirmed);

        $missing = [];
        foreach ($requiredCounts as $key => $count) {
            $delta = $count['count'] - ($confirmedCounts[$key]['count'] ?? 0);
            for ($i = 0; $i < $delta; $i++) {
                $missing[] = $requiredCounts[$key]['display'];
            }
        }

        $unexpected = [];
        foreach ($confirmedCounts as $key => $count) {
            $delta = $count['count'] - ($requiredCounts[$key]['count'] ?? 0);
            for ($i = 0; $i < $delta; $i++) {
                $unexpected[] = $confirmedCounts[$key]['display'];
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
     * @return array<string, array{count: int, display: string}>
     */
    private function buildCounts(array $values): array
    {
        $counts = [];

        foreach ($values as $value) {
            $key = mb_strtolower($value);
            if (!isset($counts[$key])) {
                $counts[$key] = [
                    'count' => 0,
                    'display' => $value,
                ];
            }

            $counts[$key]['count']++;
        }

        return $counts;
    }
}
