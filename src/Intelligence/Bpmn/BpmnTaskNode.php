<?php

namespace App\Intelligence\Bpmn;

final readonly class BpmnTaskNode
{
    public string $kind;

    public function __construct(
        public string $id,
        public string $stepKey,
        public string $label,
        public bool $required = false,
        public BpmnNodeMetrics $metrics = new BpmnNodeMetrics()
    ) {
        $this->kind = 'task';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'step_key' => $this->stepKey,
            'label' => $this->label,
            'required' => $this->required,
            'metrics' => $this->metrics->toArray(),
        ];
    }
}
