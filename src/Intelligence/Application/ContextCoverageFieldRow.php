<?php

namespace App\Intelligence\Application;

final readonly class ContextCoverageFieldRow
{
    /**
     * @param array<int, string> $observedTypes
     * @param array<int, mixed> $exampleValues
     */
    public function __construct(
        public string $fieldKey,
        public float $coverage,
        public int $presentCount,
        public int $missingCount,
        public array $observedTypes,
        public array $exampleValues
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'fieldKey' => $this->fieldKey,
            'coverage' => $this->coverage,
            'presentCount' => $this->presentCount,
            'missingCount' => $this->missingCount,
            'observedTypes' => $this->observedTypes,
            'exampleValues' => $this->exampleValues,
        ];
    }
}
