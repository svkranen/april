<?php

namespace App\Intelligence\Application;

final readonly class ProcessResetResult
{
    public function __construct(
        public int $processEvents,
        public int $processInstances,
        public int $contextSnapshots,
        public int $deviations,
        public int $analysisResults,
        public bool $dryRun
    ) {
    }
}
