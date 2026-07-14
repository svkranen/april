<?php

namespace App\Intelligence\Application;

final readonly class ProcessTemplateDocumentation
{
    /**
     * @param array<string, int|string> $summary
     * @param array<int, array<string, mixed>> $steps
     * @param array<int, array<string, string|null>> $transitions
     * @param array<int, array<string, mixed>> $parallelGroups
     * @param array<int, array<string, mixed>> $decisionPoints
     * @param array<int, array<string, string|null>> $fieldMappings
     * @param array<string, int|string|null> $contextPolicy
     * @param array<int, array<string, string>> $signChecks
     * @param array<string, mixed> $access
     * @param array<int, string> $warnings
     * @param array<int, string> $assumptions
     */
    public function __construct(
        public string $processKey,
        public string $version,
        public ?string $title,
        public string $sourceSystem,
        public string $templatePath,
        public string $generatedAt,
        public array $summary,
        public array $steps,
        public array $transitions,
        public array $parallelGroups,
        public array $decisionPoints,
        public array $requiredContextFields,
        public array $fieldMappings,
        public array $contextPolicy,
        public array $signChecks,
        public array $access,
        public array $warnings,
        public array $assumptions
    ) {
    }
}
