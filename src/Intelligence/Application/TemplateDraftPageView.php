<?php

namespace App\Intelligence\Application;

/**
 * View model for the read-only "create template draft" page.
 *
 * Combines the selection form state (observed process keys of the document,
 * chosen scope and template key) with an optional computed draft preview.
 */
final readonly class TemplateDraftPageView
{
    /**
     * @param array<string, bool> $knownTemplatesByProcessKey observed process keys of the document -> template known
     */
    public function __construct(
        public string $documentUuid,
        public ?int $documentVersion,
        public array $knownTemplatesByProcessKey,
        public string $selectedScope,
        public ?string $selectedTemplateKey = null,
        public ?string $inputError = null,
        public ?TemplateDraftPreview $preview = null
    ) {
    }

    public function hasObservedProcessKeys(): bool
    {
        return $this->knownTemplatesByProcessKey !== [];
    }

    public function defaultJourneyKey(): string
    {
        $processKeys = array_keys($this->knownTemplatesByProcessKey);

        return $processKeys === [] ? 'journey-draft' : $processKeys[0].'-journey';
    }
}
