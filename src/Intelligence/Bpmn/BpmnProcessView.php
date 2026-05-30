<?php

namespace App\Intelligence\Bpmn;

final readonly class BpmnProcessView
{
    /**
     * @param array<int, BpmnTaskNode|BpmnGatewayNode|BpmnParallelGroupNode> $nodes
     * @param array<int, BpmnTransitionEdge> $edges
     * @param array<int, string> $diagnostics
     */
    public function __construct(
        public string $templateKey,
        public string $templateVersion,
        public array $nodes,
        public array $edges,
        public array $diagnostics = []
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'template_key' => $this->templateKey,
            'template_version' => $this->templateVersion,
            'nodes' => array_map(
                static fn (BpmnTaskNode|BpmnGatewayNode|BpmnParallelGroupNode $node): array => $node->toArray(),
                $this->nodes
            ),
            'edges' => array_map(
                static fn (BpmnTransitionEdge $edge): array => $edge->toArray(),
                $this->edges
            ),
            'diagnostics' => $this->diagnostics,
        ];
    }
}
