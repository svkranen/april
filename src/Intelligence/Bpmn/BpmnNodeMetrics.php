<?php

namespace App\Intelligence\Bpmn;

final readonly class BpmnNodeMetrics
{
    public function __construct(
        public int $historicalCount = 0,
        public float $avgDuration = 0.0,
        public int $openDocuments = 0,
        public float $intensity = 0.0
    ) {
    }

    /**
     * @return array{historical_count: int, avg_duration: float, open_documents: int, intensity: float}
     */
    public function toArray(): array
    {
        return [
            'historical_count' => $this->historicalCount,
            'avg_duration' => $this->avgDuration,
            'open_documents' => $this->openDocuments,
            'intensity' => $this->intensity,
        ];
    }
}
