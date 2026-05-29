<?php

namespace App\Intelligence\Infrastructure\EventStore;

use App\Intelligence\Domain\ProcessEvent;
use App\Intelligence\Port\EventStore;
use App\Intelligence\Port\EventStoreResult;
use RuntimeException;

final class JsonFileEventStore implements EventStore
{
    public function __construct(
        private readonly string $path
    ) {
    }

    public function append(ProcessEvent $event): EventStoreResult
    {
        $this->ensureDirectory();
        $handle = fopen($this->path, 'c+');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Event-Store "%s" konnte nicht geoeffnet werden.', $this->path));
        }

        try {
            flock($handle, LOCK_EX);
            $events = $this->readEvents($handle);
            foreach ($events as $stored) {
                if ($stored->externalEventKey === $event->externalEventKey) {
                    return new EventStoreResult($stored, true);
                }
            }

            $stored = $event->id === null ? $event->withId(count($events) + 1) : $event;
            fseek($handle, 0, SEEK_END);
            fwrite($handle, json_encode($stored->toArray(), JSON_THROW_ON_ERROR).PHP_EOL);
            fflush($handle);

            return new EventStoreResult($stored, false);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function count(): int
    {
        if (!is_file($this->path)) {
            return 0;
        }

        $count = 0;
        foreach (file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (trim($line) !== '') {
                $count++;
            }
        }

        return $count;
    }

    private function ensureDirectory(): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Event-Store-Verzeichnis "%s" konnte nicht erstellt werden.', $directory));
        }
    }

    /**
     * @param resource $handle
     * @return array<int, ProcessEvent>
     */
    private function readEvents($handle): array
    {
        rewind($handle);
        $events = [];
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($data)) {
                $events[] = ProcessEvent::fromArray($data);
            }
        }

        return $events;
    }
}
