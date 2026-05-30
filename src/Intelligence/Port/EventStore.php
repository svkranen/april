<?php

namespace App\Intelligence\Port;

use App\Intelligence\Domain\ProcessEventRecord;

interface EventStore
{
    public function append(ProcessEventRecord $event): EventStoreResult;

    public function attachProcessInstance(ProcessEventRecord $event, int $processInstanceId): ProcessEventRecord;

    public function count(): int;
}
