<?php

namespace App\Intelligence\Domain;

final readonly class SuggestedTransition
{
    public function __construct(
        public string $from,
        public string $to,
        public ?int $observedCount = null,
        public ?float $confidence = null
    ) {
    }
}
