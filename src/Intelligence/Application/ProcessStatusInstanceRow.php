<?php

namespace App\Intelligence\Application;

use DateTimeImmutable;

final readonly class ProcessStatusInstanceRow
{
    public function __construct(
        public ?int $id,
        public ?string $documentUuid,
        public int $documentVersion,
        public string $currentStepKey,
        public DateTimeImmutable $lastEventAt,
        public string $status
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'documentUuid' => $this->documentUuid,
            'documentVersion' => $this->documentVersion,
            'currentStepKey' => $this->currentStepKey,
            'lastEventAt' => $this->lastEventAt->format(DATE_ATOM),
            'status' => $this->status,
        ];
    }
}
