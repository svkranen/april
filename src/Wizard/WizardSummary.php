<?php

namespace App\Wizard;

final readonly class WizardSummary
{
    /**
     * @param array<int, string> $audience
     * @param array<string, string> $scenario
     */
    public function __construct(
        public string $key,
        public string $title,
        public ?string $description,
        public array $audience,
        public array $scenario
    ) {
    }
}
