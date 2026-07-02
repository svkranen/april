<?php

namespace App\Intelligence\Application;

final readonly class JourneyTemplateStepCheckResult
{
    /**
     * @param array<int, string> $messages
     */
    public function __construct(
        public string $status,
        public string $journeyStepKey,
        public string $stepType,
        public ?string $processKey,
        public bool $required,
        public bool $applicable,
        public bool $knownDetailTemplate,
        public ?int $documentVersion,
        public ?\DateTimeImmutable $startedAt = null,
        public ?\DateTimeImmutable $completedAt = null,
        public array $messages = []
    ) {
    }

    public function isSatisfied(): bool
    {
        return in_array($this->status, [
            JourneyTemplateCheckService::STEP_PROCESS_EXISTS,
            JourneyTemplateCheckService::STEP_CONDITION_NOT_APPLICABLE,
        ], true);
    }
}
