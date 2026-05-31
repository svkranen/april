<?php

namespace App\Intelligence\Domain;

final readonly class ProcessTemplateDecisionRule
{
    public function __construct(
        public ?ProcessTemplateRuleCondition $condition,
        public ?string $expectedNextStepKey,
        public bool $isElse = false,
        public ?string $expectedNextParallelGroupKey = null
    ) {
    }

    public function targetsParallelGroup(): bool
    {
        return $this->expectedNextParallelGroupKey !== null;
    }
}
