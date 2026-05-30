<?php

namespace App\Intelligence\Domain;

final readonly class ProcessTemplateParallelGroup
{
    /**
     * @param array<int, string> $requiredStepKeys
     */
    public function __construct(
        public string $key,
        public ?string $after,
        public array $requiredStepKeys,
        public string $order = 'any'
    ) {
    }
}
