<?php

namespace App\Intelligence\Application;

final readonly class DocumentJourneyEventView
{
    /**
     * @param array<string, mixed>|null $contextAttributes
     * @param array<int, string> $contextWarnings
     */
    public function __construct(
        public string $externalEventKey,
        public string $eventKey,
        public string $stepKey,
        public string $processKey,
        public int $documentVersion,
        public \DateTimeImmutable $occurredAt,
        public \DateTimeImmutable $receivedAt,
        public ?int $id,
        public ?int $processInstanceId,
        public string $eventPhase,
        public bool $duplicate,
        public ?array $contextAttributes,
        public array $contextWarnings,
        public ?string $contextSource
    ) {
    }

    public static function fromTimelineRow(DocumentTimelineEventRow $row): self
    {
        $attributes = is_array($row->contextSummary['attributes'] ?? null)
            ? $row->contextSummary['attributes']
            : null;
        $warnings = is_array($row->contextSummary['warnings'] ?? null)
            ? array_values(array_filter($row->contextSummary['warnings'], 'is_string'))
            : [];
        $source = isset($row->contextSummary['source']) && is_scalar($row->contextSummary['source'])
            ? (string) $row->contextSummary['source']
            : null;

        return new self(
            $row->externalEventKey,
            $row->eventKey,
            $row->stepKey,
            $row->processKey,
            $row->documentVersion,
            $row->occurredAt,
            $row->receivedAt,
            $row->id,
            $row->processInstanceId,
            $row->eventPhase,
            $row->duplicate,
            $attributes,
            $warnings,
            $source
        );
    }

    public function hasContext(): bool
    {
        return $this->contextAttributes !== null && $this->contextAttributes !== [];
    }
}
