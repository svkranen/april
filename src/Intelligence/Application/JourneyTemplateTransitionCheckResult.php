<?php

namespace App\Intelligence\Application;

final readonly class JourneyTemplateTransitionCheckResult
{
    /**
     * @param array<int, string> $messages
     */
    public function __construct(
        public string $status,
        public string $fromStepKey,
        public string $toStepKey,
        public ?\DateTimeImmutable $fromOccurredAt = null,
        public ?\DateTimeImmutable $toOccurredAt = null,
        public array $messages = []
    ) {
    }
}
