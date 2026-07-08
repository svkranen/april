<?php

namespace App\Wizard;

final readonly class WizardCompletionCheckResult
{
    public const STATUS_UNKNOWN = 'unknown';
    public const STATUS_WARNING = 'warning';

    public function __construct(
        public string $stepKey,
        public string $type,
        public string $status,
        public string $message
    ) {
    }
}
