<?php

namespace App\Intelligence\Application;

use DateTimeImmutable;

final readonly class ContextHistoryEntry
{
    /**
     * @param array<string, mixed> $contextJson
     * @param array<int, string> $warnings
     */
    public function __construct(
        public DateTimeImmutable $at,
        public ?string $externalEventKey,
        public ?string $eventKey,
        public ?string $stepKey,
        public int $documentVersion,
        public ?int $processInstanceId,
        public array $contextJson,
        public array $warnings
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'at' => $this->at->format(DATE_ATOM),
            'externalEventKey' => $this->externalEventKey,
            'eventKey' => $this->eventKey,
            'stepKey' => $this->stepKey,
            'documentVersion' => $this->documentVersion,
            'processInstanceId' => $this->processInstanceId,
            'contextJson' => $this->contextJson,
            'warnings' => $this->warnings,
        ];
    }
}
