<?php

namespace App\Intelligence\Infrastructure\Context;

use App\Intelligence\Application\ContextSnapshotStore;
use App\Intelligence\Domain\ContextSnapshot;

final class InMemoryContextSnapshotStore implements ContextSnapshotStore
{
    /** @var array<int, ContextSnapshot> */
    private array $snapshots = [];

    public function save(ContextSnapshot $snapshot): ContextSnapshot
    {
        $this->snapshots[] = $snapshot;

        return $snapshot;
    }

    public function count(): int
    {
        return count($this->snapshots);
    }

    /**
     * @return array<int, ContextSnapshot>
     */
    public function all(): array
    {
        return $this->snapshots;
    }
}
