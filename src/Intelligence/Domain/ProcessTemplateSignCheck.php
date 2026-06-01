<?php

namespace App\Intelligence\Domain;

final readonly class ProcessTemplateSignCheck
{
    public const OPERATOR_REQUIRED_SUBSET_OF_ACTUAL = 'required_subset_of_actual';

    public function __construct(
        public string $key,
        public string $requiredSetField,
        public string $actualSetField,
        public string $operator = self::OPERATOR_REQUIRED_SUBSET_OF_ACTUAL,
        public ?string $label = null
    ) {
    }
}
