<?php

namespace App\Intelligence\Domain;

use DateTimeImmutable;

final readonly class ContextSnapshot
{
    public DocumentRef $document;
    public DateTimeImmutable $capturedAt;
    public DateTimeImmutable $loadedAt;
    public ?DateTimeImmutable $occurredAt;
    public ?int $freshnessSeconds;
    public ?bool $isFreshForDecisionCheck;

    /** @var array<string, mixed> */
    public array $attributes;

    /** @var array<int, string> */
    public array $warnings;

    /**
     * @param array<string, mixed> $attributes
     * @param array<int, string> $warnings
     */
    public function __construct(
        DocumentRef $document,
        DateTimeImmutable $capturedAt,
        array $attributes = [],
        array $warnings = [],
        public ?string $processKey = null,
        public ?string $externalEventKey = null,
        public ?int $processInstanceId = null,
        ?DateTimeImmutable $occurredAt = null,
        ?DateTimeImmutable $loadedAt = null,
        public ?int $incomingEventId = null,
        ?int $freshnessSeconds = null,
        ?bool $isFreshForDecisionCheck = null
    ) {
        $this->document = $document;
        $this->capturedAt = $loadedAt ?? $capturedAt;
        $this->loadedAt = $loadedAt ?? $capturedAt;
        $this->occurredAt = $occurredAt;
        $this->freshnessSeconds = $occurredAt !== null
            ? $this->loadedAt->getTimestamp() - $occurredAt->getTimestamp()
            : $freshnessSeconds;
        $this->isFreshForDecisionCheck = $isFreshForDecisionCheck;
        $this->attributes = $attributes;
        $this->warnings = $warnings;
    }
}
