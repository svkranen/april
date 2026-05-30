<?php

namespace App\Intelligence\Domain;

final readonly class ProcessTemplateRuleCondition
{
    public function __construct(
        public string $field,
        public string $operator,
        public mixed $value
    ) {
    }
}
