<?php

namespace App\Intelligence\Application;

final readonly class IncomingEventProcessingResult
{
    public function __construct(
        public int $processed,
        public int $failed,
        public int $dead
    ) {
    }
}
