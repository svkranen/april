<?php

namespace App\Intelligence\Bpmn;

final readonly class BpmnTransitionEdge
{
    public function __construct(
        public string $id,
        public string $fromNodeId,
        public string $toNodeId,
        public string $source,
        public string $status = 'expected',
        public ?string $conditionLabel = null,
        public ?string $ruleKey = null,
        public int $observedCount = 0,
        public float $percentage = 0.0,
        public bool $isAllowed = true,
        public float $intensity = 0.0
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'from_node_id' => $this->fromNodeId,
            'to_node_id' => $this->toNodeId,
            'source' => $this->source,
            'status' => $this->status,
            'condition_label' => $this->conditionLabel,
            'rule_key' => $this->ruleKey,
            'observed_count' => $this->observedCount,
            'percentage' => $this->percentage,
            'is_allowed' => $this->isAllowed,
            'intensity' => $this->intensity,
        ];
    }
}
