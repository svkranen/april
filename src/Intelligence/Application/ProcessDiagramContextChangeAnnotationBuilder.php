<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateDecisionRule;
use App\Intelligence\Domain\ProcessTemplateDecisionRuleEvaluator;

final class ProcessDiagramContextChangeAnnotationBuilder
{
    public function __construct(
        private readonly DocumentTimelineContextDiffBuilder $diffBuilder = new DocumentTimelineContextDiffBuilder(),
        private readonly ProcessTemplateDecisionRuleEvaluator $decisionRuleEvaluator = new ProcessTemplateDecisionRuleEvaluator()
    ) {
    }

    /**
     * @param array<int, DocumentTimelineEventRow> $events
     * @return array<int, ProcessDiagramContextChangeAnnotation>
     */
    public function build(ProcessTemplate $template, array $events): array
    {
        if ($template->decisionPoints === [] || count($events) < 2) {
            return [];
        }

        $diffsByEventKey = $this->diffBuilder->build($events);
        $annotations = [];

        for ($index = 0, $max = count($events) - 1; $index < $max; ++$index) {
            $event = $events[$index];
            $nextEvent = $events[$index + 1];
            $context = $this->contextFromEvent($event);
            if ($context === null) {
                continue;
            }

            foreach ($template->decisionPoints as $decisionPoint) {
                if ($decisionPoint->after !== $event->stepKey) {
                    continue;
                }

                $matchedRule = $this->decisionRuleEvaluator->matchingRule($decisionPoint, $context);
                if ($matchedRule === null || !$this->isDecisionViolation($template, $matchedRule, $nextEvent->stepKey)) {
                    continue;
                }

                $relevantFields = $this->relevantFields($decisionPoint->requiredFields, $matchedRule);
                foreach ($this->laterRelevantChanges($events, $diffsByEventKey, $index + 1, $relevantFields) as $change) {
                    $annotation = new ProcessDiagramContextChangeAnnotation(
                        (string) $change['field'],
                        $change['from'] ?? null,
                        $change['to'] ?? null,
                        [$decisionPoint->key],
                        'decision:'.$decisionPoint->key
                    );
                    $annotations[$annotation->key()] = $annotation;
                }
            }
        }

        return array_values($annotations);
    }

    private function isDecisionViolation(ProcessTemplate $template, ProcessTemplateDecisionRule $rule, string $actualNextStepKey): bool
    {
        if ($rule->expectedNextStepKey !== null) {
            return $rule->expectedNextStepKey !== $actualNextStepKey;
        }

        if ($rule->expectedNextParallelGroupKey === null) {
            return false;
        }

        foreach ($template->parallelGroups as $parallelGroup) {
            if ($parallelGroup->key === $rule->expectedNextParallelGroupKey) {
                return !in_array($actualNextStepKey, $parallelGroup->requiredStepKeys, true);
            }
        }

        return true;
    }

    /**
     * @param array<int, string> $requiredFields
     * @return array<string, true>
     */
    private function relevantFields(array $requiredFields, ProcessTemplateDecisionRule $rule): array
    {
        $fields = array_fill_keys($requiredFields, true);
        if ($rule->condition !== null) {
            $fields[$rule->condition->field] = true;
        }

        return $fields;
    }

    /**
     * @param array<int, DocumentTimelineEventRow> $events
     * @param array<string, array<int, array<string, mixed>>> $diffsByEventKey
     * @param array<string, true> $relevantFields
     * @return array<int, array<string, mixed>>
     */
    private function laterRelevantChanges(array $events, array $diffsByEventKey, int $startIndex, array $relevantFields): array
    {
        $changes = [];
        for ($index = $startIndex, $max = count($events); $index < $max; ++$index) {
            foreach ($diffsByEventKey[$events[$index]->externalEventKey] ?? [] as $diff) {
                $field = $diff['field'] ?? null;
                if (!is_string($field) || !isset($relevantFields[$field])) {
                    continue;
                }

                $changes[] = $diff;
            }
        }

        return $changes;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function contextFromEvent(DocumentTimelineEventRow $event): ?array
    {
        $attributes = $event->contextSummary['attributes'] ?? null;

        return is_array($attributes) ? $attributes : null;
    }
}
