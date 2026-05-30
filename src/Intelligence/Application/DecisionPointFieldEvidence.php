<?php

namespace App\Intelligence\Application;

final readonly class DecisionPointFieldEvidence
{
    /**
     * @param array<string, array<int, mixed>> $observedValuesByNextStep
     */
    public function __construct(
        public string $fieldKey,
        public array $observedValuesByNextStep,
        public float $coverage,
        public int $distinctValueCount,
        public string $reason
    ) {
    }
}
