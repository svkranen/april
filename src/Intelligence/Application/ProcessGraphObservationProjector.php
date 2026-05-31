<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessGraph;
use App\Intelligence\Domain\ProcessGraphEdge;
use App\Intelligence\Domain\ProcessGraphMetrics;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateDecisionRule;
use App\Intelligence\Domain\ProcessTemplateDecisionRuleEvaluator;
use App\Intelligence\Domain\ProcessTemplateParallelGroup;

final class ProcessGraphObservationProjector
{
    public function __construct(
        private readonly ProcessTemplateDecisionRuleEvaluator $decisionRuleEvaluator = new ProcessTemplateDecisionRuleEvaluator()
    ) {
    }

    /**
     * @param array<string, mixed>|null $context
     */
    public function project(ProcessGraph $graph, ProcessTemplate $template, string $fromStep, string $toStep, ?array $context = null): ObservedTransitionProjection
    {
        $expectedEdges = $this->expectedEdges($graph);
        if (isset($expectedEdges[ProcessGraphMetrics::edgeKey($fromStep, $toStep)])) {
            return new ObservedTransitionProjection(ObservedTransitionProjection::EXPECTED_DIRECT, [[$fromStep, $toStep]]);
        }

        $decisionProjection = $this->projectDecision($template, $graph, $fromStep, $toStep, $context);
        if ($decisionProjection !== null) {
            return $decisionProjection;
        }

        $parallelTransitionProjection = $this->projectTransitionToParallelGroup($template, $graph, $fromStep, $toStep);
        if ($parallelTransitionProjection !== null) {
            return $parallelTransitionProjection;
        }

        if ($this->sameAnyOrderParallelGroup($template, $fromStep, $toStep) !== null) {
            return new ObservedTransitionProjection(ObservedTransitionProjection::EXPECTED_GROUP_INTERNAL);
        }

        $groupCompleteProjection = $this->projectParallelGroupComplete($template, $graph, $fromStep, $toStep);
        if ($groupCompleteProjection !== null) {
            return $groupCompleteProjection;
        }

        return new ObservedTransitionProjection(ObservedTransitionProjection::UNEXPECTED, [[$fromStep, $toStep]]);
    }

    /**
     * @return array<string, true>
     */
    private function expectedEdges(ProcessGraph $graph): array
    {
        $edges = [];
        foreach ($graph->edges as $edge) {
            if ($edge->style !== ProcessGraphEdge::STYLE_FLOW) {
                continue;
            }

            $edges[ProcessGraphMetrics::edgeKey($edge->from, $edge->to)] = true;
        }

        return $edges;
    }

    /**
     * @param array<string, mixed>|null $context
     */
    private function projectDecision(ProcessTemplate $template, ProcessGraph $graph, string $fromStep, string $toStep, ?array $context): ?ObservedTransitionProjection
    {
        foreach ($template->decisionPoints as $decisionPoint) {
            if ($decisionPoint->after !== $fromStep) {
                continue;
            }

            $rules = $context === null
                ? $decisionPoint->rules
                : array_filter(
                    [$this->decisionRuleEvaluator->matchingRule($decisionPoint, $context)],
                    static fn (?ProcessTemplateDecisionRule $rule): bool => $rule !== null
                );

            foreach ($rules as $rule) {
                $gatewayId = 'decision:'.$decisionPoint->key;
                if ($rule->expectedNextStepKey === $toStep) {
                    return new ObservedTransitionProjection(
                        ObservedTransitionProjection::EXPECTED_VIA_DECISION,
                        [[$fromStep, $gatewayId], [$gatewayId, $toStep]]
                    );
                }

                if ($rule->expectedNextParallelGroupKey === null) {
                    continue;
                }

                $group = $this->parallelGroupByKey($template, $rule->expectedNextParallelGroupKey);
                if ($group === null || !in_array($toStep, $group->requiredStepKeys, true)) {
                    continue;
                }

                $groupStartId = $this->parallelGroupStartId($group);
                $projectedEdges = [[$fromStep, $gatewayId], [$gatewayId, $groupStartId]];
                if ($this->hasGraphEdge($graph, $groupStartId, $toStep)) {
                    $projectedEdges[] = [$groupStartId, $toStep];
                }

                return new ObservedTransitionProjection(ObservedTransitionProjection::EXPECTED_VIA_DECISION, $projectedEdges);
            }
        }

        return null;
    }

    private function projectTransitionToParallelGroup(ProcessTemplate $template, ProcessGraph $graph, string $fromStep, string $toStep): ?ObservedTransitionProjection
    {
        foreach ($template->transitions as $transition) {
            if ($transition->from !== $fromStep || $transition->toParallelGroup === null) {
                continue;
            }

            $group = $this->parallelGroupByKey($template, $transition->toParallelGroup);
            if ($group === null || !in_array($toStep, $group->requiredStepKeys, true)) {
                continue;
            }

            $groupStartId = $this->parallelGroupStartId($group);
            $projectedEdges = [[$fromStep, $groupStartId]];
            if ($this->hasGraphEdge($graph, $groupStartId, $toStep)) {
                $projectedEdges[] = [$groupStartId, $toStep];
            }

            return new ObservedTransitionProjection(ObservedTransitionProjection::EXPECTED_VIA_PARALLEL_GROUP, $projectedEdges);
        }

        return null;
    }

    private function projectParallelGroupComplete(ProcessTemplate $template, ProcessGraph $graph, string $fromStep, string $toStep): ?ObservedTransitionProjection
    {
        foreach ($template->parallelGroups as $group) {
            if ($group->nextStepKey !== $toStep || !in_array($fromStep, $group->requiredStepKeys, true)) {
                continue;
            }

            $joinId = 'parallel_join:'.$group->key;
            $projectedEdges = [];
            if ($this->hasGraphEdge($graph, $fromStep, $joinId)) {
                $projectedEdges[] = [$fromStep, $joinId];
            }
            if ($this->hasGraphEdge($graph, $joinId, $toStep)) {
                $projectedEdges[] = [$joinId, $toStep];
            }

            return new ObservedTransitionProjection(ObservedTransitionProjection::EXPECTED_GROUP_COMPLETE, $projectedEdges);
        }

        return null;
    }

    private function sameAnyOrderParallelGroup(ProcessTemplate $template, string $fromStep, string $toStep): ?ProcessTemplateParallelGroup
    {
        foreach ($template->parallelGroups as $group) {
            if ($group->order === 'any' && in_array($fromStep, $group->requiredStepKeys, true) && in_array($toStep, $group->requiredStepKeys, true)) {
                return $group;
            }
        }

        return null;
    }

    private function parallelGroupByKey(ProcessTemplate $template, string $key): ?ProcessTemplateParallelGroup
    {
        foreach ($template->parallelGroups as $group) {
            if ($group->key === $key) {
                return $group;
            }
        }

        return null;
    }

    private function parallelGroupStartId(ProcessTemplateParallelGroup $group): string
    {
        return $group->nextStepKey === null ? 'parallel:'.$group->key : 'parallel_start:'.$group->key;
    }

    private function hasGraphEdge(ProcessGraph $graph, string $from, string $to): bool
    {
        foreach ($graph->edges as $edge) {
            if ($edge->style === ProcessGraphEdge::STYLE_FLOW && $edge->from === $from && $edge->to === $to) {
                return true;
            }
        }

        return false;
    }
}
