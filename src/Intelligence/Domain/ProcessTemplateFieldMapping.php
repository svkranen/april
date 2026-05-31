<?php

namespace App\Intelligence\Domain;

final readonly class ProcessTemplateFieldMapping
{
    public const STABILITY_IMMUTABLE = 'immutable';
    public const STABILITY_MUTABLE = 'mutable';
    public const STABILITY_SNAPSHOT_REQUIRED = 'snapshot_required';

    public function __construct(
        public string $fieldKey,
        public string $source,
        public ?string $tagName = null,
        public ?string $tagId = null,
        public ?string $valueType = null,
        public ?string $stability = null
    ) {
    }

    public function isImmutable(): bool
    {
        return $this->stability === self::STABILITY_IMMUTABLE;
    }
}
