<?php

namespace App\Intelligence\Domain;

final readonly class ProcessGraphEdgeMetrics
{
    public function __construct(
        public string $from,
        public string $to,
        public int $observedCount = 0,
        public int $deviationCount = 0,
        public bool $isExpected = true,
        public bool $isObservedOnly = false
    ) {
    }
}
