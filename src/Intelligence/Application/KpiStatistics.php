<?php

namespace App\Intelligence\Application;

final readonly class KpiStatistics
{
    private function __construct(
        public int $count,
        public ?float $average,
        public ?float $median,
        public ?float $p90,
        public ?float $maximum
    ) {
    }

    /** @param list<float> $seconds Only measured durations, never null placeholders. */
    public static function fromSeconds(array $seconds): self
    {
        $count = count($seconds);
        if ($count === 0) {
            return new self(0, null, null, null, null);
        }
        sort($seconds, SORT_NUMERIC);
        $middle = intdiv($count, 2);
        $median = $count % 2 === 0 ? ($seconds[$middle - 1] + $seconds[$middle]) / 2 : $seconds[$middle];

        // Nearest rank: an observed value even for small samples, no interpolation.
        return new self($count, array_sum($seconds) / $count, $median, $seconds[(int) ceil(0.9 * $count) - 1], $seconds[$count - 1]);
    }
}
