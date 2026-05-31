<?php

namespace App\Intelligence\Application;

final readonly class ProcessTemplateCheckResult
{
    /**
     * @param array<int, string> $expectedSteps
     * @param array<int, string> $actualSteps
     * @param array<int, string> $deviations
     * @param array<int, string> $parallelGroupMessages
     * @param array<int, string> $contextIssues
     */
    public function __construct(
        public array $expectedSteps,
        public array $actualSteps,
        public array $deviations,
        public array $parallelGroupMessages = [],
        public array $contextIssues = [],
        public ?string $contextStatus = null
    ) {
    }

    public function isOk(): bool
    {
        return $this->deviations === [] && $this->contextIssues === [];
    }

    public function status(): string
    {
        if ($this->deviations !== []) {
            return 'DEVIATION';
        }

        if ($this->contextStatus !== null) {
            return $this->contextStatus;
        }

        return $this->contextIssues === [] ? 'OK' : 'WARNING';
    }
}
