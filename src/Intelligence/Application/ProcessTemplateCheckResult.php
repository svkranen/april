<?php

namespace App\Intelligence\Application;

final readonly class ProcessTemplateCheckResult
{
    /**
     * @param array<int, string> $expectedSteps
     * @param array<int, string> $actualSteps
     * @param array<int, string> $deviations
     */
    public function __construct(
        public array $expectedSteps,
        public array $actualSteps,
        public array $deviations
    ) {
    }

    public function isOk(): bool
    {
        return $this->deviations === [];
    }

    public function status(): string
    {
        return $this->isOk() ? 'OK' : 'DEVIATION';
    }
}
