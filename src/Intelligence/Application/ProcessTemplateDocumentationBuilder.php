<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateDecisionRule;
use App\Intelligence\Domain\ProcessTemplateVisibilityCheck;
use Psr\Clock\ClockInterface;

final readonly class ProcessTemplateDocumentationBuilder
{
    public function __construct(
        private AccessCoverageReportBuilder $coverageReportBuilder,
        private ClockInterface $clock
    ) {
    }

    public function build(
        ProcessTemplate $template,
        string $templatePath
    ): ProcessTemplateDocumentation {
        $coverage = $this->coverageReportBuilder->build($template);
        $visibilityChecks = count($coverage->checks);

        return new ProcessTemplateDocumentation(
            $template->key,
            $template->version,
            $template->name,
            $template->sourceSystem,
            $templatePath,
            $this->clock->now()->format(DATE_ATOM),
            [
                'initialStep' => $template->initialStepKey ?? '-',
                'steps' => count($template->steps),
                'transitions' => count($template->transitions),
                'parallelGroups' => count($template->parallelGroups),
                'decisionPoints' => count($template->decisionPoints),
                'signChecks' => count($template->signChecks),
                'accessProbes' => count($template->accessProbes),
                'visibilityChecks' => $visibilityChecks,
                'manualAccessTests' => count($template->manualAccessTests),
            ],
            array_map(static fn ($step): array => [
                'key' => $step->key,
                'label' => $step->name ?? $step->key,
                'type' => $step->type,
                'required' => $step->required,
                'beforeChecks' => array_map(static fn ($check): array => self::visibilityCheck($check), $step->beforeVisibilityChecks),
                'afterChecks' => array_map(static fn ($check): array => self::visibilityCheck($check), $step->afterVisibilityChecks),
            ], $template->steps),
            array_map(static fn ($transition): array => [
                'from' => $transition->from,
                'to' => $transition->to,
                'toParallelGroup' => $transition->toParallelGroup,
            ], $template->transitions),
            array_map(static fn ($group): array => [
                'key' => $group->key,
                'after' => $group->after,
                'requiredSteps' => $group->requiredStepKeys,
                'order' => $group->order,
                'next' => $group->nextStepKey,
            ], $template->parallelGroups),
            array_map(fn ($point): array => [
                'key' => $point->key,
                'after' => $point->after,
                'requiredFields' => $point->requiredFields,
                'rules' => array_map(fn (ProcessTemplateDecisionRule $rule): array => $this->decisionRule($rule), $point->rules),
            ], $template->decisionPoints),
            $template->contextProfileRequiredFields,
            array_map(static fn ($mapping): array => [
                'field' => $mapping->fieldKey,
                'source' => $mapping->source,
                'tagId' => $mapping->tagId,
                'tagName' => $mapping->tagName,
                'valueType' => $mapping->valueType,
                'stability' => $mapping->stability,
            ], array_values($template->fieldMappings)),
            $template->contextPolicy === null ? [] : [
                'snapshotMaxDelaySeconds' => $template->contextPolicy->snapshotMaxDelaySeconds,
                'snapshotStaleBehavior' => $template->contextPolicy->snapshotStaleBehavior,
            ],
            array_map(static fn ($check): array => [
                'key' => $check->key,
                'label' => $check->label ?? $check->key,
                'requiredSetField' => $check->requiredSetField,
                'actualSetField' => $check->actualSetField,
                'operator' => $check->operator,
            ], $template->signChecks),
            $this->access($template, $coverage),
            $this->warnings($template),
            [
                'Das YAML-Template beschreibt den Soll-Prozess und ist die Source of Truth.',
                'Runtime-Ergebnisse, VisibilityCheckResults, Timelines und Heatmaps liegen separat und sind nicht Bestandteil dieser Dokumentation.',
                'Das Quellsystem bleibt ein Adapter; APRIL ist keine vollstaendige ACL-Engine.',
                'Prozess-Templates liegen unter config/april/process-templates/; templates/ ist Symfony/Twig vorbehalten.',
                'before/after sind Kontrollphasen am gleichen stepKey und keine eigenen Prozessschritte.',
            ]
        );
    }

    private static function visibilityCheck(ProcessTemplateVisibilityCheck $check): array
    {
        return [
            'key' => $check->key,
            'phase' => $check->phase,
            'expectedProfile' => $check->expectedProfileKey,
            'expectedProfileResolver' => $check->expectedProfileResolverKey,
            'retryPolicy' => $check->retryPolicyKey,
        ];
    }

    private function decisionRule(ProcessTemplateDecisionRule $rule): array
    {
        return [
            'else' => $rule->isElse,
            'field' => $rule->condition?->field,
            'operator' => $rule->condition?->operator,
            'value' => $rule->condition?->value,
            'target' => $rule->expectedNextStepKey ?? $rule->expectedNextParallelGroupKey,
            'targetType' => $rule->targetsParallelGroup() ? 'parallel group' : 'step',
        ];
    }

    private function access(ProcessTemplate $template, AccessCoverageReport $coverage): array
    {
        return [
            'coverage' => $coverage->summary,
            'probes' => array_map(static fn ($probe): array => [
                'key' => $probe->key,
                'sourceSystem' => $probe->sourceSystem,
                'type' => $probe->type,
                'description' => $probe->description,
                'maxDocuments' => $probe->maxDocuments,
            ], array_values($template->accessProbes)),
            'profiles' => array_map(static fn ($profile): array => [
                'key' => $profile->key,
                'visible' => $profile->expectedVisibleInProbeKeys,
                'notVisible' => $profile->expectedNotVisibleInProbeKeys,
            ], array_values($template->visibilityProfiles)),
            'resolvers' => array_map(static fn ($resolver): array => [
                'key' => $resolver->key,
                'field' => $resolver->field,
                'map' => $resolver->map,
            ], array_values($template->visibilityProfileResolvers)),
            'retryPolicies' => array_map(static fn ($policy): array => [
                'key' => $policy->key,
                'attemptsAfterSeconds' => $policy->attemptsAfterSeconds,
                'forbiddenFound' => $policy->forbiddenFound,
                'expectedMissing' => $policy->expectedMissingAfterLastAttempt,
                'probeTooLarge' => $policy->probeTooLarge,
            ], array_values($template->visibilityRetryPolicies)),
            'checks' => $coverage->checks,
            'manualTests' => $coverage->manualTests,
        ];
    }

    private function warnings(ProcessTemplate $template): array
    {
        $warnings = [];
        foreach ($template->contextProfileRequiredFields as $field) {
            if (!isset($template->fieldMappings[$field])) {
                $warnings[] = sprintf('Required context field "%s" has no field mapping.', $field);
            }
        }
        foreach ($template->fieldMappings as $mapping) {
            if ($mapping->source !== 'event_context' && $mapping->tagId === null && $mapping->tagName === null) {
                $warnings[] = sprintf('Field mapping "%s" has no tag_id or tag_name.', $mapping->fieldKey);
            }
        }
        foreach ($template->steps as $step) {
            foreach (array_merge($step->beforeVisibilityChecks, $step->afterVisibilityChecks) as $check) {
                if ($check->expectedProfileKey !== null && !isset($template->visibilityProfiles[$check->expectedProfileKey])) {
                    $warnings[] = sprintf('Visibility check "%s" references undefined profile "%s".', $check->key, $check->expectedProfileKey);
                }
                if ($check->expectedProfileResolverKey !== null && !isset($template->visibilityProfileResolvers[$check->expectedProfileResolverKey])) {
                    $warnings[] = sprintf('Visibility check "%s" references undefined resolver "%s".', $check->key, $check->expectedProfileResolverKey);
                }
                if ($check->retryPolicyKey !== null && !isset($template->visibilityRetryPolicies[$check->retryPolicyKey])) {
                    $warnings[] = sprintf('Visibility check "%s" references undefined retry policy "%s".', $check->key, $check->retryPolicyKey);
                }
            }
        }

        return $warnings;
    }
}
