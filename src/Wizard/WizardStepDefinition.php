<?php

namespace App\Wizard;

final readonly class WizardStepDefinition
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public string $key,
        public string $title,
        public array $data = []
    ) {
    }
}
