<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ContextSnapshot;

interface ContextSnapshotStore
{
    public function save(ContextSnapshot $snapshot): ContextSnapshot;

    public function count(): int;
}
