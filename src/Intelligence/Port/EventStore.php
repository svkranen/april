<?php

namespace App\Intelligence\Port;

use App\Intelligence\Domain\ProcessEvent;

interface EventStore
{
    public function append(ProcessEvent $event): EventStoreResult;

    public function count(): int;
}
