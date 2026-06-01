<?php

namespace App\Intelligence\Infrastructure\Process;

use App\Intelligence\Application\ContextSnapshotHistoryProvider;
use App\Intelligence\Domain\ContextSnapshot;

final readonly class InMemoryContextSnapshotHistoryProvider implements ContextSnapshotHistoryProvider
{
    /**
     * @param array<int, ContextSnapshot> $snapshots
     */
    public function __construct(
        private array $snapshots = []
    ) {
    }

    public function snapshotsForDocument(string $documentUuid, string $processKey): array
    {
        return array_values(array_filter(
            $this->snapshots,
            static fn (ContextSnapshot $snapshot): bool => $snapshot->document->externalUuid === $documentUuid
                && $snapshot->processKey === $processKey
        ));
    }
}
