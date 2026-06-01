<?php

namespace App\Intelligence\Domain;

use DateTimeImmutable;

final readonly class KpiEligibilityResult
{
    public function __construct(
        public bool $isEligible,
        public ?ProcessVersion $processVersion,
        public ?string $exclusionReason,
        public ?DateTimeImmutable $firstEventAt,
        public ?DateTimeImmutable $lastEventAt,
        public ?string $firstStep,
        public bool $crossedVersionBoundary = false
    ) {
    }
}
