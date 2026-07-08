<?php

namespace App\Wizard;

final readonly class WizardPrerequisiteCheckResult
{
    public const STATUS_OK = 'ok';
    public const STATUS_WARNING = 'warning';
    public const STATUS_MISSING = 'missing';

    public function __construct(
        public string $key,
        public string $type,
        public string $status,
        public string $message
    ) {
    }
}
