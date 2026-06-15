<?php

namespace App\Intelligence\Domain;

final readonly class ProcessTemplateStep
{
    /**
     * @param array<int, ProcessTemplateVisibilityCheck> $beforeVisibilityChecks
     * @param array<int, ProcessTemplateVisibilityCheck> $afterVisibilityChecks
     */
    public function __construct(
        public string $key,
        public ?string $name = null,
        public string $type = 'normal',
        public array $beforeVisibilityChecks = [],
        public array $afterVisibilityChecks = []
    ) {
    }
}
