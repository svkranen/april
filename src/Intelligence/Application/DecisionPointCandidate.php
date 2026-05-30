<?php

namespace App\Intelligence\Application;

final readonly class DecisionPointCandidate
{
    /**
     * @param array<int, string> $observedNextSteps
     * @param array<int, string> $documentUuids
     */
    public function __construct(
        public string $afterStepKey,
        public array $observedNextSteps,
        public int $documentCount,
        public float $confidence,
        public array $documentUuids
    ) {
    }
}
