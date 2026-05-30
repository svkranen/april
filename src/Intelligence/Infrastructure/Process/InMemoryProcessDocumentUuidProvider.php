<?php

namespace App\Intelligence\Infrastructure\Process;

use App\Intelligence\Application\ProcessDocumentUuidProvider;
use App\Intelligence\Application\ProcessDocumentRef;
use App\Intelligence\Domain\ProcessEventRecord;
use DateTimeImmutable;

final class InMemoryProcessDocumentUuidProvider implements ProcessDocumentUuidProvider
{
    /**
     * @param array<int, ProcessEventRecord> $events
     */
    public function __construct(
        private readonly array $events = []
    ) {
    }

    public function documentUuidsForProcess(string $processKey, ?DateTimeImmutable $since = null, ?int $limit = null): array
    {
        return array_map(
            static fn (ProcessDocumentRef $ref): string => $ref->documentUuid,
            $this->documentRefsForProcess($processKey, $since, $limit)
        );
    }

    public function documentRefsForProcess(string $processKey, ?DateTimeImmutable $since = null, ?int $limit = null): array
    {
        $latestSeenAtByDocumentUuid = [];
        $refsByDocumentUuid = [];

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
                $refsByDocumentUuid[$event->documentUuid] = new ProcessDocumentRef(
                    $event->documentUuid,
                    $event->documentExternalId !== '' ? $event->documentExternalId : null,
                    $event->documentVersion
                );
            }
        }

        arsort($latestSeenAtByDocumentUuid);
        $documentRefs = array_map(
            static fn (string $documentUuid): ProcessDocumentRef => $refsByDocumentUuid[$documentUuid],
            array_keys($latestSeenAtByDocumentUuid)
        );

        return $limit === null ? $documentRefs : array_slice($documentRefs, 0, $limit);
    }
}
