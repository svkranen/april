<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessTemplateSuggestionNote;
use App\Intelligence\Domain\ProcessTemplateSuggestionWarning;

/**
 * Read-only preview of a template draft suggested from a single document.
 *
 * Carries the exact YAML a user would get from the CLI suggestion command plus
 * the outcome of re-parsing that YAML through the regular template factory, so
 * the UI shows the draft exactly as the catalog would later interpret it.
 */
final readonly class TemplateDraftPreview
{
    /**
     * @param array<int, ProcessTemplateSuggestionWarning> $warnings
     * @param array<int, ProcessTemplateSuggestionNote> $suggestions
     */
    private function __construct(
        public string $documentUuid,
        public ?int $documentVersion,
        public string $templateKey,
        public string $scope,
        public bool $found,
        public ?string $errorMessage,
        public ?string $yaml,
        public array $warnings,
        public array $suggestions,
        public ?string $validationError,
        public ?string $mermaidCode,
        public int $stepCount,
        public int $transitionCount
    ) {
    }

    public static function notFound(string $documentUuid, ?int $documentVersion, string $templateKey, string $scope): self
    {
        return new self($documentUuid, $documentVersion, $templateKey, $scope, false, null, null, [], [], null, null, 0, 0);
    }

    public static function error(string $documentUuid, ?int $documentVersion, string $templateKey, string $scope, string $errorMessage): self
    {
        return new self($documentUuid, $documentVersion, $templateKey, $scope, false, $errorMessage, null, [], [], null, null, 0, 0);
    }

    /**
     * @param array<int, ProcessTemplateSuggestionWarning> $warnings
     * @param array<int, ProcessTemplateSuggestionNote> $suggestions
     */
    public static function fromSuggestion(
        string $documentUuid,
        ?int $documentVersion,
        string $templateKey,
        string $scope,
        string $yaml,
        array $warnings,
        array $suggestions,
        ?string $validationError,
        ?string $mermaidCode,
        int $stepCount,
        int $transitionCount
    ): self {
        return new self(
            $documentUuid,
            $documentVersion,
            $templateKey,
            $scope,
            true,
            null,
            $yaml,
            $warnings,
            $suggestions,
            $validationError,
            $mermaidCode,
            $stepCount,
            $transitionCount
        );
    }

    public function isValid(): bool
    {
        return $this->found && $this->validationError === null;
    }

    public function downloadFilename(): string
    {
        return $this->templateKey.'.yaml';
    }
}
