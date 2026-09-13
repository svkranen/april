<?php

namespace App\Intelligence\Domain;

final readonly class ObservedProcessTransition
{
    public function __construct(
        public string $fromStep,
        public string $toStep,
        public int $sequenceNumber
    ) {
    }
}
