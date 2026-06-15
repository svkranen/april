<?php

namespace App\Intelligence\Application;

final readonly class VisibilityProfileResolutionResult
{
    public function __construct(
        public ?string $profileKey,
        public ?string $reason = null
    ) {
    }

    public function isResolved(): bool
    {
        return $this->profileKey !== null;
    }
}
