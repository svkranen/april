<?php

namespace App\Intelligence\Bpmn;

use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateDecisionRule;
use App\Intelligence\Domain\ProcessTemplateParallelGroup;
use App\Intelligence\Domain\ProcessTemplateRuleCondition;
use App\Intelligence\Domain\ProcessTemplateStep;

final class ProcessTemplateBpmnViewBuilder
{
    /**
     * @param array<string, mixed>|null $heatmapReport
     */
    public function build(ProcessTemplate $template, ?array $heatmapReport = null): BpmnProcessView
    {
        $metricsByStep = $this->metricsByStep($heatmapReport);
        $nodes = [];
        $edges = [];
        $logicalEdgeIndex = [];
        $requiredStepKeys = $template->requiredStepKeys !== []
            ? $template->requiredStepKeys
            : $this->templateStepKeys($template);

        foreach ($template->steps as $step) {
            $nodes[] = new BpmnTaskNode(
                $this->taskNodeId($step->key),
                $step->key,
                $step->key,
                in_array($step->key, $requiredStepKeys, true),
                $metricsByStep[$step->key] ?? new BpmnNodeMetrics()
            );
        }

        for ($i = 0, $max = count($requiredStepKeys) - 1; $i < $max; ++$i) {
            $this->addEdge(
                $edges,
                $logicalEdgeIndex,
                $requiredStepKeys[$i],
                $requiredStepKeys[$i + 1],
                new BpmnTransitionEdge(
                    $this->edgeId('required', $requiredStepKeys[$i], $requiredStepKeys[$i + 1]),
                    $this->taskNodeId($requiredStepKeys[$i]),
                    $this->taskNodeId($requiredStepKeys[$i + 1]),
                    'required_step',
                    'expected'
                )
            );
        }

        foreach ($template->transitions as $transition) {
            if ($transition->to === null) {
                continue;
            }

            $this->addEdge(
                $edges,
                $logicalEdgeIndex,
                $transition->from,
                $transition->to,
                new BpmnTransitionEdge(
                    $this->edgeId('template', $transition->from, $transition->to),
                    $this->taskNodeId($transition->from),
                    $this->taskNodeId($transition->to),
                    'template_transition',
                    'expected'
                )
            );
        }

        foreach ($template->decisionPoints as $decisionPoint) {
            $gatewayId = $this->gatewayNodeId($decisionPoint->key);
            $nodes[] = new BpmnGatewayNode(
                $gatewayId,
                $decisionPoint->key,
                $decisionPoint->after,
                $decisionPoint->requiredFields,
                array_map(
                    fn (ProcessTemplateDecisionRule $rule): array => $this->ruleToArray($rule),
                    $decisionPoint->rules
                )
            );

            if ($decisionPoint->after !== null) {
                $edges[] = new BpmnTransitionEdge(
                    $this->edgeId('decision-enter', $decisionPoint->after, $decisionPoint->key),
                    $this->taskNodeId($decisionPoint->after),
                    $gatewayId,
                    'decision_point',
                    'expected'
                );
            }

            foreach ($decisionPoint->rules as $rule) {
                if ($decisionPoint->after !== null) {
                    $targetKey = $rule->expectedNextParallelGroupKey ?? $rule->expectedNextStepKey;
                    if ($targetKey === null) {
                        continue;
                    }

                    $targetNodeId = $rule->expectedNextParallelGroupKey !== null
                        ? $this->parallelGroupNodeId($rule->expectedNextParallelGroupKey)
                        : $this->taskNodeId($rule->expectedNextStepKey);

                    $this->addEdge(
                        $edges,
                        $logicalEdgeIndex,
                        $decisionPoint->after,
                        $targetKey,
                        new BpmnTransitionEdge(
                            $this->edgeId('decision-rule', $decisionPoint->key, $targetKey, $this->conditionLabel($rule)),
                            $gatewayId,
                            $targetNodeId,
                            'decision_rule',
                            'expected',
                            $this->conditionLabel($rule),
                            $decisionPoint->key
                        )
                    );
                }
            }
        }

        foreach ($template->parallelGroups as $group) {
            $groupId = $this->parallelGroupNodeId($group->key);
            $nodes[] = new BpmnParallelGroupNode(
                $groupId,
                $group->key,
                $group->after,
                $group->requiredStepKeys,
                $group->order,
                $this->parallelGroupMetrics($group, $metricsByStep)
            );

            if ($group->after !== null) {
                $edges[] = new BpmnTransitionEdge(
                    $this->edgeId('parallel-enter', $group->after, $group->key),
                    $this->taskNodeId($group->after),
                    $groupId,
                    'parallel_group',
                    'expected'
                );
            }

            foreach ($group->requiredStepKeys as $stepKey) {
                $edges[] = new BpmnTransitionEdge(
                    $this->edgeId('parallel-member', $group->key, $stepKey),
                    $groupId,
                    $this->taskNodeId($stepKey),
                    'parallel_group',
                    'expected'
                );
            }

            if ($group->order === 'any') {
                foreach ($group->requiredStepKeys as $from) {
                    foreach ($group->requiredStepKeys as $to) {
                        if ($from === $to) {
                            continue;
                        }

                        $this->addEdge(
                            $edges,
                            $logicalEdgeIndex,
                            $from,
                            $to,
                            new BpmnTransitionEdge(
                                $this->edgeId('parallel-any', $group->key, $from, $to),
                                $this->taskNodeId($from),
                                $this->taskNodeId($to),
                                'parallel_group',
                                'expected'
                            )
                        );
                    }
                }
            }
        }

        $this->applyFlowHeatmap($edges, $logicalEdgeIndex, $heatmapReport);

        return new BpmnProcessView(
            $template->key,
            $template->version,
            $nodes,
            array_values($edges)
        );
    }

    /**
     * @param array<string, BpmnTransitionEdge> $edges
     * @param array<string, string> $logicalEdgeIndex
     * @param array<string, mixed>|null $heatmapReport
     */
    private function applyFlowHeatmap(array &$edges, array &$logicalEdgeIndex, ?array $heatmapReport): void
    {
        $transitions = $heatmapReport['flow_heatmap']['transitions'] ?? [];
        if (!is_array($transitions)) {
            return;
        }

        foreach ($transitions as $transition) {
            if (!is_array($transition) || !isset($transition['from'], $transition['to'])) {
                continue;
            }

            $from = (string) $transition['from'];
            $to = (string) $transition['to'];
            $logicalKey = $this->logicalEdgeKey($from, $to);
            $edgeId = $logicalEdgeIndex[$logicalKey] ?? null;
            $isAllowed = $edgeId !== null;

            if ($edgeId === null) {
                $edgeId = $this->edgeId('observed', $from, $to);
                $logicalEdgeIndex[$logicalKey] = $edgeId;
                $edges[$edgeId] = new BpmnTransitionEdge(
                    $edgeId,
                    $this->taskNodeId($from),
                    $this->taskNodeId($to),
                    'observed',
                    'observed_unexpected',
                    null,
                    null,
                    (int) ($transition['count'] ?? 0),
                    (float) ($transition['percentage'] ?? 0.0),
                    false,
                    (float) ($transition['intensity'] ?? 0.0)
                );
                continue;
            }

            $edge = $edges[$edgeId];
            $edges[$edgeId] = new BpmnTransitionEdge(
                $edge->id,
                $edge->fromNodeId,
                $edge->toNodeId,
                $edge->source,
                'observed_allowed',
                $edge->conditionLabel,
                $edge->ruleKey,
                (int) ($transition['count'] ?? 0),
                (float) ($transition['percentage'] ?? 0.0),
                $isAllowed,
                (float) ($transition['intensity'] ?? 0.0)
            );
        }
    }

    /**
     * @param array<string, BpmnTransitionEdge> $edges
     * @param array<string, string> $logicalEdgeIndex
     */
    private function addEdge(array &$edges, array &$logicalEdgeIndex, string $logicalFrom, string $logicalTo, BpmnTransitionEdge $edge): void
    {
        if (isset($edges[$edge->id])) {
            return;
        }

        $edges[$edge->id] = $edge;
        $logicalEdgeIndex[$this->logicalEdgeKey($logicalFrom, $logicalTo)] ??= $edge->id;
    }

    /**
     * @param array<string, mixed>|null $heatmapReport
     * @return array<string, BpmnNodeMetrics>
     */
    private function metricsByStep(?array $heatmapReport): array
    {
        $steps = $heatmapReport['duration_heatmap']['steps'] ?? [];
        if (!is_array($steps)) {
            return [];
        }

        $metrics = [];
        foreach ($steps as $step) {
            if (!is_array($step) || !isset($step['step'])) {
                continue;
            }

            $intensity = $step['intensity'] ?? [];
            $metrics[(string) $step['step']] = new BpmnNodeMetrics(
                (int) ($step['historical']['completed_documents'] ?? 0),
                (float) ($step['historical']['avg_duration_minutes'] ?? 0.0),
                (int) ($step['current']['open_documents'] ?? 0),
                is_array($intensity) && $intensity !== [] ? (float) max($intensity) : 0.0
            );
        }

        return $metrics;
    }

    /**
     * @param array<string, BpmnNodeMetrics> $metricsByStep
     */
    private function parallelGroupMetrics(ProcessTemplateParallelGroup $group, array $metricsByStep): BpmnNodeMetrics
    {
        $historicalCount = 0;
        $durationSum = 0.0;
        $durationCount = 0;
        $openDocuments = 0;
        $intensity = 0.0;

        foreach ($group->requiredStepKeys as $stepKey) {
            $metrics = $metricsByStep[$stepKey] ?? null;
            if ($metrics === null) {
                continue;
            }

            $historicalCount += $metrics->historicalCount;
            $durationSum += $metrics->avgDuration;
            ++$durationCount;
            $openDocuments += $metrics->openDocuments;
            $intensity = max($intensity, $metrics->intensity);
        }

        return new BpmnNodeMetrics(
            $historicalCount,
            $durationCount === 0 ? 0.0 : round($durationSum / $durationCount, 2),
            $openDocuments,
            $intensity
        );
    }

    /**
     * @return array<int, string>
     */
    private function templateStepKeys(ProcessTemplate $template): array
    {
        return array_map(
            static fn (ProcessTemplateStep $step): string => $step->key,
            $template->steps
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function ruleToArray(ProcessTemplateDecisionRule $rule): array
    {
        return [
            'condition_label' => $this->conditionLabel($rule),
            'expect_next' => $rule->expectedNextStepKey,
            'expect_next_parallel_group' => $rule->expectedNextParallelGroupKey,
            'is_else' => $rule->isElse,
        ];
    }

    private function conditionLabel(ProcessTemplateDecisionRule $rule): string
    {
        if ($rule->isElse || $rule->condition === null) {
            return 'else';
        }

        return $this->conditionToLabel($rule->condition);
    }

    private function conditionToLabel(ProcessTemplateRuleCondition $condition): string
    {
        return sprintf('%s %s %s', $condition->field, $condition->operator, $this->formatValue($condition->value));
    }

    private function formatValue(mixed $value): string
    {
        if (is_array($value)) {
            return '['.implode(', ', array_map(fn (mixed $item): string => $this->formatValue($item), $value)).']';
        }

        if (is_string($value)) {
            return sprintf('"%s"', $value);
        }

        if ($value === null) {
            return '<missing>';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    private function taskNodeId(string $stepKey): string
    {
        return 'task:'.$stepKey;
    }

    private function gatewayNodeId(string $decisionPointKey): string
    {
        return 'gateway:'.$decisionPointKey;
    }

    private function parallelGroupNodeId(string $parallelGroupKey): string
    {
        return 'parallel:'.$parallelGroupKey;
    }

    private function edgeId(string ...$parts): string
    {
        return 'edge:'.implode(':', array_map(
            static fn (string $part): string => str_replace(["\0", ':'], ['', '_'], $part),
            $parts
        ));
    }

    private function logicalEdgeKey(string $from, string $to): string
    {
        return $from."\0".$to;
    }
}
