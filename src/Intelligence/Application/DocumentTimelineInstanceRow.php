<?php

namespace App\Intelligence\Application;

final readonly class DocumentTimelineInstanceRow
{
    public function __construct(
        public ?int $id,
        public string $processKey,
        public int $documentVersion,
        public string $currentStepKey,
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
            'processKey' => $this->processKey,
            'documentVersion' => $this->documentVersion,
            'currentStepKey' => $this->currentStepKey,
            'status' => $this->status,
        ];
    }
}
