<?php

namespace App\Intelligence\Domain;

use DateTimeImmutable;

final readonly class ObservedStepVisit
{
    public function __construct(
        public string $stepKey,
        public int $sequenceNumber,
        public DateTimeImmutable $occurredAt,
        public string $eventKey
    ) {
    }
}
