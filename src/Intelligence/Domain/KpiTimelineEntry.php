<?php

namespace App\Intelligence\Domain;

use DateTimeImmutable;

final readonly class KpiTimelineEntry
{
    public function __construct(
        public string $stepKey,
        public DateTimeImmutable $occurredAt
    ) {
    }
}
