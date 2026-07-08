<?php

namespace App\Wizard;

final readonly class WizardStepView
{
    /**
     * @param array<int, string> $concepts
     * @param array<int, array<string, string>> $links
     * @param array<int, array<string, string>> $prerequisites
     * @param array<int, array<string, string>> $completion
     */
    public function __construct(
        public string $key,
        public string $title,
        public ?string $description,
        public ?string $goal,
        public array $concepts,
        public array $links,
        public array $prerequisites,
        public array $completion
    ) {
    }
}
