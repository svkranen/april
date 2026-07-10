<?php

namespace App\Intelligence\Domain;

final readonly class ProcessTemplate
{
    /**
     * @param array<int, ProcessTemplateStep> $steps
     * @param array<int, ProcessTemplateTransition> $transitions
     * @param array<int, ProcessTemplateParallelGroup> $parallelGroups
     * @param array<int, string> $contextProfileRequiredFields
     * @param array<string, ProcessTemplateFieldMapping> $fieldMappings
     * @param array<int, ProcessTemplateDecisionPoint> $decisionPoints
     * @param array<int, string> $requiredStepKeys
     * @param array<int, ProcessTemplateSignCheck> $signChecks
     * @param array<string, ProcessTemplateAccessProbe> $accessProbes
     * @param array<string, ProcessTemplateVisibilityProfile> $visibilityProfiles
     * @param array<string, ProcessTemplateVisibilityProfileResolver> $visibilityProfileResolvers
     * @param array<string, ProcessTemplateVisibilityRetryPolicy> $visibilityRetryPolicies
     * @param array<int, ProcessTemplateManualAccessTest> $manualAccessTests
     * @param array<int, ProcessTemplateCrossProcessRoutingRule> $crossProcessRoutingRules
     */
    public function __construct(
        public string $key,
        public string $version = 'draft',
        public ?string $name = null,
        public ?string $initialStepKey = null,
        public array $steps = [],
        public array $transitions = [],
        public array $parallelGroups = [],
        public array $contextProfileRequiredFields = [],
        public array $fieldMappings = [],
        public array $decisionPoints = [],
        public array $requiredStepKeys = [],
        public ?ProcessTemplateConnector $connector = null,
        public ?ProcessTemplateContextPolicy $contextPolicy = null,
        public array $signChecks = [],
        public array $accessProbes = [],
        public array $visibilityProfiles = [],
        public array $visibilityProfileResolvers = [],
        public array $visibilityRetryPolicies = [],
        public array $manualAccessTests = [],
        public array $crossProcessRoutingRules = [],
        public string $scope = 'process',
        public string $sourceSystem = 'amagno',
        public ?ProcessTemplateMatch $match = null
    ) {
    }

    /**
     * Copy of this template with a replaced journey match; every other field
     * is carried over unchanged. Null removes the explicit match, so the
     * legacy fallback of the match resolver applies again.
     */
    public function withMatch(?ProcessTemplateMatch $match): self
    {
        return new self(
            $this->key,
            $this->version,
            $this->name,
            $this->initialStepKey,
            steps: $this->steps,
            transitions: $this->transitions,
            parallelGroups: $this->parallelGroups,
            contextProfileRequiredFields: $this->contextProfileRequiredFields,
            fieldMappings: $this->fieldMappings,
            decisionPoints: $this->decisionPoints,
            requiredStepKeys: $this->requiredStepKeys,
            connector: $this->connector,
            contextPolicy: $this->contextPolicy,
            signChecks: $this->signChecks,
            accessProbes: $this->accessProbes,
            visibilityProfiles: $this->visibilityProfiles,
            visibilityProfileResolvers: $this->visibilityProfileResolvers,
            visibilityRetryPolicies: $this->visibilityRetryPolicies,
            manualAccessTests: $this->manualAccessTests,
            crossProcessRoutingRules: $this->crossProcessRoutingRules,
            scope: $this->scope,
            sourceSystem: $this->sourceSystem,
            match: $match
        );
    }
}
