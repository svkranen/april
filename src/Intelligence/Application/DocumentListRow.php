<?php

namespace App\Intelligence\Application;

use DateTimeImmutable;

/**
 * One row of the per-template document list: a document APRIL already has
 * process events for, with cheap aggregate metadata.
 */
final readonly class DocumentListRow
{
    public function __construct(
        public string $documentUuid,
        public ?string $documentExternalId,
        public ?int $documentVersion,
        public int $eventCount,
        public ?DateTimeImmutable $lastEventAt
    ) {
    }
}
