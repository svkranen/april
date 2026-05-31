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
        public array $warnings,
        public ?DateTimeImmutable $occurredAt = null,
        public ?DateTimeImmutable $loadedAt = null,
        public ?int $incomingEventId = null,
        public ?int $freshnessSeconds = null,
        public ?bool $isFreshForDecisionCheck = null
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
            'occurredAt' => $this->occurredAt?->format(DATE_ATOM),
            'loadedAt' => ($this->loadedAt ?? $this->capturedAt)->format(DATE_ATOM),
            'incomingEventId' => $this->incomingEventId,
            'freshnessSeconds' => $this->freshnessSeconds,
            'isFreshForDecisionCheck' => $this->isFreshForDecisionCheck,
            'context' => $this->context,
            'warnings' => $this->warnings,
        ];
    }
}
