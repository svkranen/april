<?php

namespace App\Intelligence\Bpmn;

final readonly class BpmnParallelGroupNode
{
    public string $kind;

    /**
     * @param array<int, string> $requiredStepKeys
     */
    public function __construct(
        public string $id,
        public string $parallelGroupKey,
        public ?string $afterStepKey,
        public array $requiredStepKeys,
        public string $order,
        public BpmnNodeMetrics $metrics = new BpmnNodeMetrics()
    ) {
        $this->kind = 'parallel_group';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'parallel_group_key' => $this->parallelGroupKey,
            'after_step_key' => $this->afterStepKey,
            'required_step_keys' => $this->requiredStepKeys,
            'order' => $this->order,
            'metrics' => $this->metrics->toArray(),
        ];
    }
}
