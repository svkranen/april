<?php

namespace App\Wizard;

final readonly class WizardView
{
    /**
     * @param array<int, string> $audience
     * @param array<string, string> $scenario
     * @param array<int, string> $concepts
     * @param array<int, array<string, string>> $prerequisites
     * @param array<string, string> $progress
     * @param array<int, WizardStepView> $steps
     * @param array<string, string> $completion
     */
    public function __construct(
        public string $key,
        public string $version,
        public string $title,
        public ?string $description,
        public array $audience,
        public array $scenario,
        public array $concepts,
        public array $prerequisites,
        public array $progress,
        public array $steps,
        public array $completion
    ) {
    }
}
