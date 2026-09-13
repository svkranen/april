<?php

namespace App\Intelligence\Domain;

use DateTimeImmutable;

final readonly class StepVisitMeasurement
{
    /**
     * An ambiguous fragment is evidence, not a countable reconstructed visit.
     * @param list<string> $eventKeys
     * @param array{before: bool, after: bool} $measurementPointCoverage Observed phases, independent of pairing and eligibility.
     */
    public function __construct(
        public string $stepKey,
        public ?int $visitNumber,
        public ?DateTimeImmutable $beforeAt,
        public ?DateTimeImmutable $afterAt,
        public string $status,
        public KpiDuration $duration,
        public array $eventKeys,
        public array $measurementPointCoverage,
        public DateTimeImmutable $firstObservedAt,
        public DateTimeImmutable $lastObservedAt
    ) {
    }
}
