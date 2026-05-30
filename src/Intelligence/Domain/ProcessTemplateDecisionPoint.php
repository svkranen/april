<?php

namespace App\Intelligence\Domain;

final readonly class ProcessTemplateDecisionPoint
{
    /**
     * @param array<int, string> $requiredFields
     * @param array<int, ProcessTemplateDecisionRule> $rules
     */
    public function __construct(
        public string $key,
        public ?string $after,
        public array $requiredFields,
        public array $rules
    ) {
    }
}
