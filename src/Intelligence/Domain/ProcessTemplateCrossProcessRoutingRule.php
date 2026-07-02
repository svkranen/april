<?php

namespace App\Intelligence\Domain;

final readonly class ProcessTemplateCrossProcessRoutingRule
{
    /**
     * @param array<string, mixed> $when Equality-shorthand conditions; all fields must match.
     */
    public function __construct(
        public string $key,
        public string $afterStep,
        public array $when,
        public string $expectedProcess
    ) {
    }
}
