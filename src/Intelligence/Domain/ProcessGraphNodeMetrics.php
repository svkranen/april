<?php

namespace App\Intelligence\Domain;

final readonly class ProcessGraphNodeMetrics
{
    public function __construct(
        public int $observedCount = 0,
        public float $avgDwellSeconds = 0.0,
        public float $medianDwellSeconds = 0.0,
        public float $p95DwellSeconds = 0.0,
        public int $deviationCount = 0,
        public ?string $nodeType = null,
        public ?int $reliableDwellCount = null,
        public ?int $flowCount = null
    ) {
    }
}
