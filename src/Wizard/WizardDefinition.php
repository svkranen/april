<?php

namespace App\Wizard;

final readonly class WizardDefinition
{
    /**
     * @param array<int, WizardStepDefinition> $steps
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $key,
        public string $version,
        public string $name,
        public array $steps,
        public string $path,
        public ?string $description = null,
        public array $metadata = []
    ) {
    }

    public function stepCount(): int
    {
        return count($this->steps);
    }
}
