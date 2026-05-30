<?php

namespace App\Intelligence\Domain;

use DateTimeImmutable;

final readonly class ProcessEvent
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $processKey,
        public string $entityType,
        public string $entityId,
        public string $eventKey,
        public DateTimeImmutable $occurredAt,
        public array $metadata = []
    ) {
    }
}
