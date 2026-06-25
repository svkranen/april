<?php

namespace App\Intelligence\Application;

/**
 * One business-level modelling decision surfaced on the template assistant. It is
 * never an automatic change - it makes a decision point visible so a human can
 * decide how to evolve the Soll process. Pure, Twig-friendly data.
 */
final readonly class TemplateModelingSuggestion
{
    /** Needs a human decision, likely a real gap. */
    public const STATUS_REVIEW = 'review';
    /** Worth looking at, not necessarily a problem. */
    public const STATUS_OPTIONAL = 'optional';
    /** Contradicts the modelled process and should be resolved. */
    public const STATUS_CRITICAL = 'critical';

    public function __construct(
        public string $type,
        public string $typeLabel,
        public string $status,
        public string $description,
        public string $rationale,
        public ?int $documentCount = null,
        public ?TemplateYamlDiffPreview $yamlDiff = null
    ) {
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_CRITICAL => 'Kritisch',
            self::STATUS_OPTIONAL => 'Optional',
            default => 'Prüfen',
        };
    }

    public function hasDocumentCount(): bool
    {
        return $this->documentCount !== null;
    }

    public function hasYamlDiff(): bool
    {
        return $this->yamlDiff !== null;
    }
}
