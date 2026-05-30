<?php

namespace App\Intelligence\Port;

use App\Intelligence\Domain\ProcessEventRecord;

final readonly class EventStoreResult
{
    public function __construct(
        public ProcessEventRecord $event,
        public bool $duplicate
    ) {
    }
}
