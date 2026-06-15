<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateDecisionRule;
use App\Intelligence\Domain\ProcessTemplateFieldMapping;
use App\Intelligence\Domain\ProcessTemplateSignCheck;
use App\Intelligence\Domain\ProcessTemplateStep;
use App\Intelligence\Domain\ProcessTemplateTransition;

/**
 * Read model for the template detail page. Translates the readonly domain
 * template into plain, human-readable rows and counts for the Twig view -
 * keeps the template free of domain traversal and makes the mapping testable.
 */
final readonly class TemplateDetailView
{
    /**
     * @param array<int, string> $requiredStepKeys
     * @param array<int, array{key: string, name: ?string, type: string, beforeChecks: int, afterChecks: int, beforeCheckKeys: array<int, string>, afterCheckKeys: array<int, string>}> $steps
     * @param array<int, array{from: string, to: ?string, parallelGroup: ?string}> $transitions
     * @param array<int, array{key: string, after: ?string, requiredFields: array<int, string>, ruleCount: int, outcomes: array<int, string>}> $decisionPoints
     * @param array<int, string> $contextProfileRequiredFields
     * @param array<int, array{fieldKey: string, source: string, tagName: ?string, tagId: ?string, valueType: ?string, stability: ?string}> $fieldMappings
     * @param array<int, array{key: string, label: ?string, requiredSetField: string, actualSetField: string, operator: string}> $signChecks
     * @param array<string, int> $accessSummary
     */
    public function __construct(
        public string $key,
        public string $version,
        public string $sourceSystem,
        public ?string $name,
        public ?string $initialStepKey,
        public array $requiredStepKeys,
        public array $steps,
        public array $transitions,
        public array $decisionPoints,
        public array $contextProfileRequiredFields,
        public array $fieldMappings,
        public array $signChecks,
        public array $accessSummary
    ) {
    }

    public static function fromTemplate(ProcessTemplate $template): self
    {
        $beforeChecks = 0;
        $afterChecks = 0;
        $steps = [];
        foreach ($template->steps as $step) {
            /** @var ProcessTemplateStep $step */
            $before = count($step->beforeVisibilityChecks);
            $after = count($step->afterVisibilityChecks);
            $beforeChecks += $before;
            $afterChecks += $after;
            $steps[] = [
                'key' => $step->key,
                'name' => $step->name,
                'type' => $step->type,
                'beforeChecks' => $before,
                'afterChecks' => $after,
                'beforeCheckKeys' => array_map(static fn ($check): string => $check->key, $step->beforeVisibilityChecks),
                'afterCheckKeys' => array_map(static fn ($check): string => $check->key, $step->afterVisibilityChecks),
            ];
        }

        $transitions = array_map(
            static fn (ProcessTemplateTransition $transition): array => [
                'from' => $transition->from,
                'to' => $transition->to,
                'parallelGroup' => $transition->toParallelGroup,
            ],
            array_values($template->transitions)
        );

        $decisionPoints = [];
        foreach ($template->decisionPoints as $decisionPoint) {
            $outcomes = [];
            foreach ($decisionPoint->rules as $rule) {
                /** @var ProcessTemplateDecisionRule $rule */
                if ($rule->expectedNextParallelGroupKey !== null) {
                    $outcomes[] = 'Parallelgruppe: '.$rule->expectedNextParallelGroupKey;
                } elseif ($rule->expectedNextStepKey !== null) {
                    $outcomes[] = $rule->expectedNextStepKey;
                }
            }

            $decisionPoints[] = [
                'key' => $decisionPoint->key,
                'after' => $decisionPoint->after,
                'requiredFields' => array_values($decisionPoint->requiredFields),
                'ruleCount' => count($decisionPoint->rules),
                'outcomes' => array_values(array_unique($outcomes)),
            ];
        }

        $fieldMappings = array_map(
            static fn (ProcessTemplateFieldMapping $mapping): array => [
                'fieldKey' => $mapping->fieldKey,
                'source' => $mapping->source,
                'tagName' => $mapping->tagName,
                'tagId' => $mapping->tagId,
                'valueType' => $mapping->valueType,
                'stability' => $mapping->stability,
            ],
            array_values($template->fieldMappings)
        );

        $signChecks = array_map(
            static fn (ProcessTemplateSignCheck $signCheck): array => [
                'key' => $signCheck->key,
                'label' => $signCheck->label,
                'requiredSetField' => $signCheck->requiredSetField,
                'actualSetField' => $signCheck->actualSetField,
                'operator' => $signCheck->operator,
            ],
            array_values($template->signChecks)
        );

        $accessSummary = [
            'accessProbes' => count($template->accessProbes),
            'visibilityProfiles' => count($template->visibilityProfiles),
            'visibilityProfileResolvers' => count($template->visibilityProfileResolvers),
            'manualAccessTests' => count($template->manualAccessTests),
            'beforeVisibilityChecks' => $beforeChecks,
            'afterVisibilityChecks' => $afterChecks,
            'totalVisibilityChecks' => $beforeChecks + $afterChecks,
        ];

        return new self(
            $template->key,
            $template->version,
            $template->sourceSystem,
            $template->name,
            $template->initialStepKey,
            array_values($template->requiredStepKeys),
            $steps,
            $transitions,
            $decisionPoints,
            array_values($template->contextProfileRequiredFields),
            $fieldMappings,
            $signChecks,
            $accessSummary
        );
    }
}
