<?php

namespace App\Intelligence\Domain;

final readonly class ProcessTemplateVisibilityCheck
{
    public function __construct(
        public string $key,
        public string $phase,
        public ?string $expectedProfileKey = null,
        public ?string $expectedProfileResolverKey = null,
        public ?string $retryPolicyKey = null,
        public ?string $sourceSystemOverride = null
    ) {
    }
}
