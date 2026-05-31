<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessGraph;
use App\Intelligence\Domain\ProcessGraphEdge;
use App\Intelligence\Domain\ProcessGraphNode;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateDecisionPoint;
use App\Intelligence\Domain\ProcessTemplateDecisionRule;
use App\Intelligence\Domain\ProcessTemplateParallelGroup;
use App\Intelligence\Domain\ProcessTemplateRuleCondition;
use App\Intelligence\Domain\ProcessTemplateStep;

final class ProcessTemplateGraphFactory
{
    private const START_ID = '__start';
    private const END_ID = '__end';

    public function create(ProcessTemplate $template): ProcessGraph
    {
        $nodes = [
            self::START_ID => new ProcessGraphNode(self::START_ID, 'Start', ProcessGraphNode::TYPE_START),
            self::END_ID => new ProcessGraphNode(self::END_ID, 'End', ProcessGraphNode::TYPE_END),
        ];
        $edges = [];
        $stepKeys = [];
        $decisionAfterKeys = [];
        $skipImplicitFromKeys = [];

        foreach ($template->steps as $step) {
            $nodes[$step->key] = $this->taskNode($step, in_array($step->key, $template->requiredStepKeys, true));
            $stepKeys[] = $step->key;
        }

        foreach ($template->decisionPoints as $decisionPoint) {
            $gatewayId = $this->decisionNodeId($decisionPoint);
            $nodes[$gatewayId] = new ProcessGraphNode($gatewayId, $decisionPoint->key, ProcessGraphNode::TYPE_EXCLUSIVE_GATEWAY);
            if ($decisionPoint->after !== null) {
                $decisionAfterKeys[$decisionPoint->after] = true;
                $edges[] = new ProcessGraphEdge($decisionPoint->after, $gatewayId);
            }

            foreach ($decisionPoint->rules as $index => $rule) {
                $targetNodeId = $rule->expectedNextParallelGroupKey !== null
                    ? $this->parallelGroupTargetNodeId($rule->expectedNextParallelGroupKey, $template)
                    : $rule->expectedNextStepKey;
                if ($targetNodeId === null) {
                    continue;
                }

                $edges[] = new ProcessGraphEdge(
                    $gatewayId,
                    $targetNodeId,
                    $this->ruleLabel($rule, $index + 1),
                    $rule->condition === null ? null : $this->conditionLabel($rule->condition)
                );
            }
        }

        foreach ($template->parallelGroups as $parallelGroup) {
            if ($parallelGroup->nextStepKey === null) {
                $groupId = $this->parallelGroupNodeId($parallelGroup);
                $nodes[$groupId] = new ProcessGraphNode($groupId, $this->parallelGroupLabel($parallelGroup), ProcessGraphNode::TYPE_PARALLEL_GROUP);

                foreach ($parallelGroup->requiredStepKeys as $stepKey) {
                    $skipImplicitFromKeys[$stepKey] = true;
                    $edges[] = new ProcessGraphEdge($stepKey, $groupId, 'part of', null, ProcessGraphEdge::STYLE_CONSTRAINT);
                }

                continue;
            }

            $startId = $this->parallelStartNodeId($parallelGroup);
            $joinId = $this->parallelJoinNodeId($parallelGroup);
            $nodes[$startId] = new ProcessGraphNode($startId, $this->parallelStartLabel($parallelGroup), ProcessGraphNode::TYPE_PARALLEL_START);
            $nodes[$joinId] = new ProcessGraphNode($joinId, $this->parallelJoinLabel($parallelGroup), ProcessGraphNode::TYPE_PARALLEL_JOIN);

            foreach ($parallelGroup->requiredStepKeys as $stepKey) {
                $skipImplicitFromKeys[$stepKey] = true;
                $edges[] = new ProcessGraphEdge($startId, $stepKey);
                $edges[] = new ProcessGraphEdge($stepKey, $joinId);
            }

            $edges[] = new ProcessGraphEdge($joinId, $parallelGroup->nextStepKey);
        }

        if ($stepKeys !== []) {
            $edges[] = new ProcessGraphEdge(self::START_ID, $template->initialStepKey ?? $stepKeys[0]);
        }

        foreach ($template->transitions as $transition) {
            if ($transition->to !== null) {
                $edges[] = new ProcessGraphEdge($transition->from, $transition->to);
                continue;
            }

            if ($transition->toParallelGroup !== null) {
                $edges[] = new ProcessGraphEdge(
                    $transition->from,
                    $this->parallelGroupTargetNodeId($transition->toParallelGroup, $template)
                );
            }
        }

        if ($template->transitions === []) {
            foreach ($stepKeys as $index => $stepKey) {
                $nextStepKey = $stepKeys[$index + 1] ?? null;
                if ($nextStepKey === null || isset($decisionAfterKeys[$stepKey]) || isset($skipImplicitFromKeys[$stepKey])) {
                    continue;
                }

                $edges[] = new ProcessGraphEdge(
                    $stepKey,
                    $nextStepKey,
                    'default order',
                    null,
                    ProcessGraphEdge::STYLE_IMPLICIT
                );
            }
        }

        $nodes = $this->addReferencedTaskNodes($nodes, $edges);
        $edges = $this->appendEndEdges($nodes, $edges);

        ksort($nodes);

        return new ProcessGraph($template->key, $template->version, $nodes, $this->uniqueEdges($edges));
    }

    private function taskNode(ProcessTemplateStep $step, bool $required): ProcessGraphNode
    {
        return new ProcessGraphNode($step->key, $step->name ?? $step->key, ProcessGraphNode::TYPE_TASK, $required);
    }

    private function decisionNodeId(ProcessTemplateDecisionPoint $decisionPoint): string
    {
        return 'decision:'.$decisionPoint->key;
    }

    private function parallelGroupNodeId(ProcessTemplateParallelGroup $parallelGroup): string
    {
        return 'parallel:'.$parallelGroup->key;
    }

    private function parallelJoinNodeId(ProcessTemplateParallelGroup $parallelGroup): string
    {
        return 'parallel_join:'.$parallelGroup->key;
    }

    private function parallelStartNodeId(ProcessTemplateParallelGroup $parallelGroup): string
    {
        return 'parallel_start:'.$parallelGroup->key;
    }

    private function parallelGroupTargetNodeId(string $parallelGroupKey, ProcessTemplate $template): string
    {
        foreach ($template->parallelGroups as $parallelGroup) {
            if ($parallelGroup->key !== $parallelGroupKey) {
                continue;
            }

            return $parallelGroup->nextStepKey === null
                ? $this->parallelGroupNodeId($parallelGroup)
                : $this->parallelStartNodeId($parallelGroup);
        }

        return 'parallel:'.$parallelGroupKey;
    }

    private function ruleLabel(ProcessTemplateDecisionRule $rule, int $priority): string
    {
        if ($rule->isElse || $rule->condition === null) {
            return '[else]';
        }

        return sprintf('[%d] %s', $priority, $this->conditionLabel($rule->condition));
    }

    private function parallelGroupLabel(ProcessTemplateParallelGroup $parallelGroup): string
    {
        return sprintf(
            'Constraint: %s, required steps: %s, order:%s',
            $parallelGroup->key,
            implode(', ', $parallelGroup->requiredStepKeys),
            $parallelGroup->order
        );
    }

    private function parallelJoinLabel(ProcessTemplateParallelGroup $parallelGroup): string
    {
        return sprintf("%s\ncomplete", $parallelGroup->key);
    }

    private function parallelStartLabel(ProcessTemplateParallelGroup $parallelGroup): string
    {
        return sprintf("%s\nstart\norder:%s", $parallelGroup->key, $parallelGroup->order);
    }

    private function conditionLabel(ProcessTemplateRuleCondition $condition): string
    {
        return sprintf('%s %s %s', $condition->field, $condition->operator, $this->valueLabel($condition->value));
    }

    private function valueLabel(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }

    /**
     * @param array<string, ProcessGraphNode> $nodes
     * @param array<int, ProcessGraphEdge> $edges
     * @return array<string, ProcessGraphNode>
     */
    private function addReferencedTaskNodes(array $nodes, array $edges): array
    {
        foreach ($edges as $edge) {
            foreach ([$edge->from, $edge->to] as $nodeId) {
                if (!isset($nodes[$nodeId])) {
                    $nodes[$nodeId] = new ProcessGraphNode($nodeId, $nodeId, ProcessGraphNode::TYPE_TASK);
                }
            }
        }

        return $nodes;
    }

    /**
     * @param array<string, ProcessGraphNode> $nodes
     * @param array<int, ProcessGraphEdge> $edges
     * @return array<int, ProcessGraphEdge>
     */
    private function appendEndEdges(array $nodes, array $edges): array
    {
        $outgoing = [];
        foreach ($edges as $edge) {
            $outgoing[$edge->from] = true;
        }

        foreach ($nodes as $node) {
            if ($node->id === self::START_ID || $node->id === self::END_ID || $node->type !== ProcessGraphNode::TYPE_TASK || isset($outgoing[$node->id])) {
                continue;
            }

            $edges[] = new ProcessGraphEdge($node->id, self::END_ID);
        }

        return $edges;
    }

    /**
     * @param array<int, ProcessGraphEdge> $edges
     * @return array<int, ProcessGraphEdge>
     */
    private function uniqueEdges(array $edges): array
    {
        $unique = [];
        foreach ($edges as $edge) {
            $key = implode("\0", [$edge->from, $edge->to, $edge->label ?? '', $edge->condition ?? '', $edge->style]);
            $unique[$key] = $edge;
        }

        return array_values($unique);
    }
}
