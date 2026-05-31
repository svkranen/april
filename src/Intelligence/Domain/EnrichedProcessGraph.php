<?php

namespace App\Intelligence\Domain;

final readonly class EnrichedProcessGraph
{
    public function __construct(
        public ProcessGraph $graph,
        public ProcessGraphMetrics $metrics
    ) {
    }
}
