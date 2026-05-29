<?php

namespace App\Intelligence\Application;

use DateTimeImmutable;

final readonly class ProcessStatusEventRow
{
    public function __construct(
        public string $externalEventKey,
        public ?string $documentUuid,
        public int $documentVersion,
        public string $stepKey,
        public DateTimeImmutable $occurredAt
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'externalEventKey' => $this->externalEventKey,
            'documentUuid' => $this->documentUuid,
            'documentVersion' => $this->documentVersion,
            'stepKey' => $this->stepKey,
            'occurredAt' => $this->occurredAt->format(DATE_ATOM),
        ];
    }
}
