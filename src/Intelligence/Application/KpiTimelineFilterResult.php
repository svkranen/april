<?php

namespace App\Intelligence\Application;

final readonly class KpiTimelineFilterResult
{
    /**
     * @param array<int, mixed> $included
     * @param array<int, mixed> $excluded
     * @param array<string, mixed> $summary
     */
    public function __construct(
        public array $included,
        public array $excluded,
        public array $summary
    ) {
    }
}
