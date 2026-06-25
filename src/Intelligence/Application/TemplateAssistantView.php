<?php

namespace App\Intelligence\Application;

/**
 * Read model for the template assistant page. A plain, human-readable projection
 * of a process template plus derived consistency checks - no domain traversal in
 * Twig, no writing, no file changes. Built by {@see TemplateAssistantAnalyzer}.
 */
final readonly class TemplateAssistantView
{
    /**
     * @param array<int, array{position: int, key: string, name: ?string, type: string}> $steps
     * @param array<int, array{from: string, toDisplay: string, fromKnown: bool, toKnown: bool, targetKind: string}> $transitions
     * @param array<int, string> $requiredStepKeys
     * @param array<int, TemplateAssistantCheck> $checks
     */
    public function __construct(
        public string $key,
        public string $version,
        public ?string $name,
        public string $sourceSystem,
        public ?string $filePath,
        public ?string $initialStepKey,
        public array $steps,
        public array $transitions,
        public array $requiredStepKeys,
        public array $checks,
        public string $overallStatus,
        public bool $structuralChecksApplicable,
        public ?string $structuralNote
    ) {
    }

    public function stepCount(): int
    {
        return count($this->steps);
    }

    public function transitionCount(): int
    {
        return count($this->transitions);
    }

    public function hasIssues(): bool
    {
        return $this->overallStatus !== TemplateAssistantCheck::STATUS_OK;
    }
}
