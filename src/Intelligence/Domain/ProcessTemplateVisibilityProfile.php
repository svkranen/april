<?php

namespace App\Intelligence\Domain;

final readonly class ProcessTemplateVisibilityProfile
{
    /**
     * @param array<int, string> $expectedVisibleInProbeKeys
     * @param array<int, string> $expectedNotVisibleInProbeKeys
     */
    public function __construct(
        public string $key,
        public array $expectedVisibleInProbeKeys = [],
        public array $expectedNotVisibleInProbeKeys = []
    ) {
    }
}
