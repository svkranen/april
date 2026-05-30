<?php

namespace App\Intelligence\Application;

use DateTimeImmutable;

final readonly class EventContextSnapshotDetails
{
    /**
     * @param array<string, mixed> $context
     * @param array<int, string> $warnings
     */
    public function __construct(
        public ?int $id,
        public DateTimeImmutable $capturedAt,
        public array $context,
        public array $warnings
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'capturedAt' => $this->capturedAt->format(DATE_ATOM),
            'context' => $this->context,
            'warnings' => $this->warnings,
        ];
    }
}
