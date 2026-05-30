<?php

namespace App\Intelligence\Bpmn;

final readonly class BpmnGatewayNode
{
    public string $kind;

    /**
     * @param array<int, string> $requiredFields
     * @param array<int, array<string, mixed>> $rules
     */
    public function __construct(
        public string $id,
        public string $decisionPointKey,
        public ?string $afterStepKey,
        public array $requiredFields = [],
        public array $rules = [],
        public BpmnNodeMetrics $metrics = new BpmnNodeMetrics()
    ) {
        $this->kind = 'decision_gateway';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'decision_point_key' => $this->decisionPointKey,
            'after_step_key' => $this->afterStepKey,
            'required_fields' => $this->requiredFields,
            'rules' => $this->rules,
            'metrics' => $this->metrics->toArray(),
        ];
    }
}
