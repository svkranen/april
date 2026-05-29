<?php

namespace App\Intelligence\Port;

use App\Intelligence\Domain\ProcessEvent;

final readonly class EventStoreResult
{
    public function __construct(
        public ProcessEvent $event,
        public bool $duplicate
    ) {
    }
}
