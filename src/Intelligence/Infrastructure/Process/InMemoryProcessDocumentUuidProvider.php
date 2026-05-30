<?php

namespace App\Intelligence\Infrastructure\Process;

use App\Intelligence\Application\ProcessDocumentUuidProvider;
use App\Intelligence\Domain\ProcessEvent;
use DateTimeImmutable;

final class InMemoryProcessDocumentUuidProvider implements ProcessDocumentUuidProvider
{
    /**
     * @param array<int, ProcessEvent> $events
     */
    public function __construct(
        private readonly array $events = []
    ) {
    }

    public function documentUuidsForProcess(string $processKey, ?DateTimeImmutable $since = null, ?int $limit = null): array
    {
        $latestSeenAtByDocumentUuid = [];

        foreach ($this->events as $event) {
            if ($event->processKey !== $processKey || $event->documentUuid === null) {
                continue;
            }

            if ($since !== null && $event->occurredAt < $since && $event->receivedAt < $since) {
                continue;
            }

            $seenAt = $event->receivedAt > $event->occurredAt ? $event->receivedAt : $event->occurredAt;
            if (!isset($latestSeenAtByDocumentUuid[$event->documentUuid])
                || $seenAt > $latestSeenAtByDocumentUuid[$event->documentUuid]) {
                $latestSeenAtByDocumentUuid[$event->documentUuid] = $seenAt;
            }
        }

        arsort($latestSeenAtByDocumentUuid);
        $documentUuids = array_keys($latestSeenAtByDocumentUuid);

        return $limit === null ? $documentUuids : array_slice($documentUuids, 0, $limit);
    }
}
