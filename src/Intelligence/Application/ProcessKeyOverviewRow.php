<?php

namespace App\Intelligence\Application;

final readonly class ProcessKeyOverviewRow
{
    public function __construct(
        public string $processKey,
        public int $documentCount,
        public int $eventCount,
        public bool $knownTemplate = false
    ) {
    }

    public function withKnownTemplate(bool $knownTemplate): self
    {
        return new self(
            $this->processKey,
            $this->documentCount,
            $this->eventCount,
            $knownTemplate
        );
    }
}
