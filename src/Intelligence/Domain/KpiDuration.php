<?php

namespace App\Intelligence\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class KpiDuration
{
    /** @param list<KpiMeasurementReason> $reasons */
    private function __construct(public ?float $seconds, public array $reasons)
    {
    }

    /** @param list<KpiMeasurementReason> $reasons */
    public static function between(?DateTimeImmutable $start, ?DateTimeImmutable $end, array $reasons): self
    {
        if ($reasons !== []) {
            return new self(null, array_values(array_unique($reasons, SORT_REGULAR)));
        }
        if ($start === null || $end === null || $end < $start) {
            throw new InvalidArgumentException('A measurable duration requires ordered endpoints.');
        }

        return new self((float) $end->format('U.u') - (float) $start->format('U.u'), []);
    }

    public function isMeasurable(): bool
    {
        return $this->seconds !== null;
    }
}
