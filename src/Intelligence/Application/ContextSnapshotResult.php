<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ContextSnapshot;

final readonly class ContextSnapshotResult
{
    /**
     * @param array<int, string> $warnings
     */
    public function __construct(
        public ContextSnapshot $snapshot,
        public array $warnings = []
    ) {
    }
}
