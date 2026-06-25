<?php

namespace App\Intelligence\Domain;

final readonly class ProcessTemplateVisibilityProfileResolver
{
    /**
     * @param array<string, string> $map
     */
    public function __construct(
        public string $key,
        public string $field,
        public array $map = []
    ) {
    }
}
