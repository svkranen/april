<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\IncomingEvent;

interface IncomingEventStore
{
    public function save(IncomingEvent $event): IncomingEvent;

    /**
     * @return array<int, IncomingEvent>
     */
    public function pending(int $limit, int $maxRetries): array;

    public function markProcessing(IncomingEvent $event): IncomingEvent;

    public function markProcessed(IncomingEvent $event): IncomingEvent;

    public function markFailed(IncomingEvent $event, string $error, int $maxRetries): IncomingEvent;

    public function count(): int;
}
