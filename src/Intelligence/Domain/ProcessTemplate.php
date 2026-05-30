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
        public array $decisionPoints = []
    ) {
    }
}
