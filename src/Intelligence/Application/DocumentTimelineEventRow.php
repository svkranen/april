<?php

namespace App\Intelligence\Application;

use DateTimeImmutable;

final readonly class DocumentTimelineEventRow
{
    /**
     * @param array<string, mixed>|null $contextSummary
     */
    public function __construct(
        public string $externalEventKey,
        public string $eventKey,
        public string $stepKey,
        public string $processKey,
        public int $documentVersion,
        public DateTimeImmutable $occurredAt,
        public ?int $processInstanceId,
        public ?array $contextSummary = null,
        public bool $duplicate = false
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'externalEventKey' => $this->externalEventKey,
            'duplicate' => $this->duplicate,
            'eventKey' => $this->eventKey,
            'stepKey' => $this->stepKey,
            'processKey' => $this->processKey,
            'documentVersion' => $this->documentVersion,
            'occurredAt' => $this->occurredAt->format(DATE_ATOM),
            'processInstanceId' => $this->processInstanceId,
            'contextSummary' => $this->contextSummary,
        ];
    }
}
