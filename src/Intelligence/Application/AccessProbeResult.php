<?php

namespace App\Intelligence\Application;

final readonly class AccessProbeResult
{
    public const ACTUAL_VISIBLE = 'visible';
    public const ACTUAL_HIDDEN = 'hidden';
    public const ACTUAL_UNKNOWN = 'unknown';
    public const ACTUAL_SKIPPED = 'skipped';

    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public string $actual,
        public ?int $documentCount = null,
        public ?string $reason = null,
        public array $details = []
    ) {
    }

    public static function visible(?int $documentCount = null, array $details = []): self
    {
        return new self(self::ACTUAL_VISIBLE, $documentCount, null, $details);
    }

    public static function hidden(?int $documentCount = null, array $details = []): self
    {
        return new self(self::ACTUAL_HIDDEN, $documentCount, null, $details);
    }

    public static function unknown(?string $reason = null, ?int $documentCount = null, array $details = []): self
    {
        return new self(self::ACTUAL_UNKNOWN, $documentCount, $reason, $details);
    }

    public static function skipped(?string $reason = null, ?int $documentCount = null, array $details = []): self
    {
        return new self(self::ACTUAL_SKIPPED, $documentCount, $reason, $details);
    }
}
