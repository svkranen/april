<?php

namespace App\Intelligence\Application;

use DateTimeImmutable;

final readonly class ProcessKeyDocumentOverviewRow
{
    public function __construct(
        public string $documentUuid,
        public ?string $documentExternalId,
        public ?int $documentVersion,
        public int $eventCount,
        public ?DateTimeImmutable $firstOccurredAt,
        public ?DateTimeImmutable $lastOccurredAt
    ) {
    }
}
