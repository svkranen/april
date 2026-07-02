<?php

namespace App\Intelligence\Application;

final readonly class DocumentJourneyProcessGroupView
{
    /**
     * @param array<int, int> $documentVersions
     */
    public function __construct(
        public string $processKey,
        public bool $knownTemplate,
        public int $eventCount,
        public int $instanceCount,
        public array $documentVersions,
        public ?\DateTimeImmutable $firstOccurredAt,
        public ?\DateTimeImmutable $lastOccurredAt
    ) {
    }
}
