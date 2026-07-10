<?php

namespace App\Intelligence\Application;

final readonly class JourneyTemplateCheckResult
{
    /**
     * @param array<int, JourneyTemplateStepCheckResult> $stepResults
     * @param array<int, JourneyTemplateTransitionCheckResult> $transitionResults
     * @param array<int, UnexpectedProcessResult> $unexpectedProcesses
     */
    public function __construct(
        public string $status,
        public string $documentUuid,
        public ?int $documentVersion,
        public string $journeyKey,
        public array $stepResults,
        public array $transitionResults,
        public array $unexpectedProcesses = []
    ) {
    }
}
