<?php

namespace App\Intelligence\Application;

final readonly class AccessCoverageReport
{
    /**
     * @param array<int, array<string, mixed>> $checks
     * @param array<int, array<string, mixed>> $manualTests
     * @param array<string, int> $summary
     */
    public function __construct(
        public string $processKey,
        public string $sourceSystem,
        public array $checks,
        public array $manualTests,
        public array $summary
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'processKey' => $this->processKey,
            'sourceSystem' => $this->sourceSystem,
            'summary' => $this->summary,
            'checks' => $this->checks,
            'manualAccessTests' => $this->manualTests,
        ];
    }
}
