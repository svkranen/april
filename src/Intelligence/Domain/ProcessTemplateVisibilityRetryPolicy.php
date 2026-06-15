<?php

namespace App\Intelligence\Domain;

final readonly class ProcessTemplateVisibilityRetryPolicy
{
    /**
     * @param array<int, int> $attemptsAfterSeconds
     */
    public function __construct(
        public string $key,
        public array $attemptsAfterSeconds = [],
        public string $forbiddenFound = 'violation',
        public string $expectedMissingAfterLastAttempt = 'warning',
        public string $probeTooLarge = 'technical_warning'
    ) {
    }
}
