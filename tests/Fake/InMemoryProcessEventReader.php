<?php

namespace App\Tests\Fake;

use App\Intelligence\Domain\ProcessEventRecord;
use App\Intelligence\Port\ProcessEventReader;

final readonly class InMemoryProcessEventReader implements ProcessEventReader
{
    /** @param list<ProcessEventRecord> $events */
    public function __construct(private array $events)
    {
    }

    public function readForProcess(string $processKey): iterable
    {
        foreach ($this->events as $event) {
            if ($event->processKey === $processKey) {
                yield $event;
            }
        }
    }
}
