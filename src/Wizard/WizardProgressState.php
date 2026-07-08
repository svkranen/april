<?php

namespace App\Wizard;

final readonly class WizardProgressState
{
    public const STATUS_UNKNOWN = 'unknown';

    public function __construct(
        public string $wizardKey,
        public ?string $stepKey,
        public string $status,
        public string $message
    ) {
    }
}
