<?php

namespace App\Intelligence\Domain;

use DateTimeImmutable;

final readonly class CanonicalEvent
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public DocumentRef $document,
        public string $stepKey,
        public ?string $actorRef,
        public DateTimeImmutable $occurredAt,
        public string $eventPhase = 'after',
        public array $attributes = []
    ) {
    }
}
