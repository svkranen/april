<?php

namespace App\Intelligence\Bpmn;

final class BpmnMermaidRenderer
{
    public function render(
        BpmnProcessView $view,
        string $mode = 'combined',
        int $minUnexpectedCount = 2,
        bool $expandParallelGroups = false
    ): string {
        $lines = ['flowchart TD'];
        $edgeClasses = [];
        $nodeClasses = [];

        foreach ($view->nodes as $node) {
            if ($node instanceof BpmnTaskNode) {
                $label = $node->required ? sprintf('%s (required)', $node->label) : $node->label;
                $lines[] = sprintf('  %s["%s"]', $this->nodeId($node->id), $this->escapeLabel($label));
                $class = $this->nodeClass($node->metrics);
                if ($class !== null) {
                    $nodeClasses[$this->nodeId($node->id)] = $class;
                }
                continue;
            }

            if ($node instanceof BpmnGatewayNode) {
                $lines[] = sprintf('  %s{"%s"}', $this->nodeId($node->id), $this->escapeLabel($node->decisionPointKey));
                continue;
            }

            if ($node instanceof BpmnParallelGroupNode) {
                $groupId = $this->nodeId($node->id);
                if ($expandParallelGroups) {
                    $lines[] = sprintf('  subgraph %s["%s"]', $groupId, $this->escapeLabel($node->parallelGroupKey));
                    foreach ($node->requiredStepKeys as $stepKey) {
                        $lines[] = sprintf('    %s', $this->nodeId('task:'.$stepKey));
                    }
                    $lines[] = '  end';
                } else {
                    $lines[] = sprintf(
                        '  %s["%s"]',
                        $groupId,
                        $this->escapeLabel(sprintf('Parallel: %s (%s)', $node->parallelGroupKey, implode(', ', $node->requiredStepKeys)))
                    );
                }

                $class = $this->nodeClass($node->metrics);
                if ($class !== null) {
                    $nodeClasses[$groupId] = $class;
                }
            }
        }

        $renderedEdgeIndex = 0;
        foreach ($view->edges as $edge) {
            if (!$this->shouldRenderEdge($edge, $mode, $minUnexpectedCount, $expandParallelGroups)) {
                continue;
            }

            $edgeLabel = $this->edgeLabel($edge, $mode);
            $lines[] = sprintf(
                '  %s -->|"%s"| %s',
                $this->nodeId($edge->fromNodeId),
                $this->sanitizeEdgeLabel($edgeLabel),
                $this->nodeId($edge->toNodeId)
            );
            $edgeClasses[$renderedEdgeIndex] = $edge->status;
            ++$renderedEdgeIndex;
        }

        $lines[] = '  classDef expected stroke:#6b7280,stroke-width:1px,fill:#f9fafb,color:#111827';
        $lines[] = '  classDef observed_allowed stroke:#2563eb,stroke-width:3px,fill:#eff6ff,color:#111827';
        $lines[] = '  classDef observed_unexpected stroke:#dc2626,stroke-width:2px,stroke-dasharray: 5 5,fill:#fef2f2,color:#111827';
        $lines[] = '  classDef missing_expected stroke:#f59e0b,stroke-width:2px,stroke-dasharray: 4 4,fill:#fffbeb,color:#111827';
        $lines[] = '  classDef hot_node stroke:#dc2626,stroke-width:3px,fill:#fee2e2,color:#111827';
        $lines[] = '  classDef warm_node stroke:#f59e0b,stroke-width:2px,fill:#fffbeb,color:#111827';

        foreach ($nodeClasses as $nodeId => $class) {
            $lines[] = sprintf('  class %s %s', $nodeId, $class);
        }

        foreach ($edgeClasses as $index => $class) {
            $lines[] = sprintf('  linkStyle %d %s', $index, $this->edgeStyle($class));
        }

        return implode("\n", $lines)."\n";
    }

    private function shouldRenderEdge(BpmnTransitionEdge $edge, string $mode, int $minUnexpectedCount, bool $expandParallelGroups): bool
    {
        if ($edge->source === 'required_step') {
            return false;
        }

        if (!$expandParallelGroups && $edge->source === 'parallel_group' && str_starts_with($edge->fromNodeId, 'parallel:')) {
            return false;
        }

        return match ($mode) {
            'expected' => $edge->source !== 'observed' && $edge->status !== 'observed_unexpected',
            'observed' => $edge->observedCount > 0 || $edge->percentage > 0.0,
            'deviations' => $edge->status === 'missing_expected'
                || ($edge->status === 'observed_unexpected' && $edge->observedCount >= $minUnexpectedCount),
            default => $edge->status !== 'observed_unexpected'
                || $edge->observedCount >= $minUnexpectedCount,
        };
    }

    private function edgeLabel(BpmnTransitionEdge $edge, string $mode): string
    {
        $parts = [];
        if ($edge->conditionLabel !== null && $edge->conditionLabel !== '') {
            $parts[] = $this->shortConditionLabel($edge->conditionLabel);
        }

        if ($mode !== 'expected' && ($edge->observedCount > 0 || $edge->percentage > 0.0)) {
            $parts[] = sprintf('%dx · %.0f%%', $edge->observedCount, $edge->percentage);
        }

        return $parts === [] ? $edge->status : implode(' · ', $parts);
    }

    private function nodeClass(BpmnNodeMetrics $metrics): ?string
    {
        if ($metrics->openDocuments >= 10 || $metrics->intensity >= 0.8) {
            return 'hot_node';
        }

        if ($metrics->openDocuments > 0 || $metrics->intensity >= 0.5) {
            return 'warm_node';
        }

        return null;
    }

    private function edgeStyle(string $status): string
    {
        return match ($status) {
            'observed_allowed' => 'stroke:#2563eb,stroke-width:3px',
            'observed_unexpected' => 'stroke:#dc2626,stroke-width:2px,stroke-dasharray:5 5',
            'missing_expected' => 'stroke:#f59e0b,stroke-width:2px,stroke-dasharray:4 4',
            default => 'stroke:#6b7280,stroke-width:1px',
        };
    }

    private function nodeId(string $id): string
    {
        $normalized = preg_replace('/[^A-Za-z0-9_]/', '_', $id) ?? $id;

        return 'n_'.trim($normalized, '_');
    }

    private function escapeLabel(string $label): string
    {
        return str_replace('"', '\"', $label);
    }

    private function sanitizeEdgeLabel(string $label): string
    {
        $label = str_replace(["\r", "\n", '"', '\\', '|'], [' ', ' ', '', '', '/'], $label);
        $label = preg_replace('/\s+/', ' ', $label) ?? $label;

        return trim($label);
    }

    private function shortConditionLabel(string $label): string
    {
        if ($label === 'else') {
            return $label;
        }

        if (preg_match('/^(.+?)\s+(eq|neq|gt|gte|lt|lte|in|exists)\s+(.+)$/', $label, $matches) !== 1) {
            return $label;
        }

        $operator = $matches[2];
        $value = trim($matches[3], '"');

        if ($operator === 'eq') {
            return $value;
        }

        return sprintf('%s %s', $operator, $value);
    }
}
