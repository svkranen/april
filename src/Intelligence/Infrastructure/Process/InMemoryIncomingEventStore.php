<?php

namespace App\Intelligence\Infrastructure\Process;

use App\Intelligence\Application\IncomingEventStore;
use App\Intelligence\Domain\DateTimeNormalizer;
use App\Intelligence\Domain\IncomingEvent;
use DateTimeImmutable;

final class InMemoryIncomingEventStore implements IncomingEventStore
{
    /** @var array<int, IncomingEvent> */
    private array $events = [];
    private int $nextId = 1;

    public function __construct(private readonly DateTimeNormalizer $dateTimeNormalizer = new DateTimeNormalizer())
    {
    }

    public function save(IncomingEvent $event): IncomingEvent
    {
        $now = $this->dateTimeNormalizer->nowUtc();
        $stored = $event->withId($this->nextId++, $now, $now);
        $this->events[(int) $stored->id] = $stored;

        return $stored;
    }

    public function pending(int $limit, int $maxRetries): array
    {
        return array_slice(array_values(array_filter(
            $this->events,
            static fn (IncomingEvent $event): bool => $event->status === IncomingEvent::STATUS_PENDING
                || ($event->status === IncomingEvent::STATUS_FAILED && $event->retryCount < $maxRetries)
        )), 0, $limit);
    }

    public function markProcessing(IncomingEvent $event): IncomingEvent
    {
        return $this->replace($event, IncomingEvent::STATUS_PROCESSING, $event->retryCount, null, null);
    }

    public function markProcessed(IncomingEvent $event): IncomingEvent
    {
        return $this->replace($event, IncomingEvent::STATUS_PROCESSED, $event->retryCount, null, $this->dateTimeNormalizer->nowUtc());
    }

    public function markFailed(IncomingEvent $event, string $error, int $maxRetries): IncomingEvent
    {
        $retryCount = $event->retryCount + 1;
        $status = $retryCount >= $maxRetries ? IncomingEvent::STATUS_DEAD : IncomingEvent::STATUS_FAILED;

        return $this->replace($event, $status, $retryCount, $error, null);
    }

    public function count(): int
    {
        return count($this->events);
    }

    /** @return array<int, IncomingEvent> */
    public function all(): array
    {
        return array_values($this->events);
    }

    private function replace(IncomingEvent $event, string $status, int $retryCount, ?string $lastError, ?DateTimeImmutable $processedAt): IncomingEvent
    {
        $stored = new IncomingEvent(
            $event->id,
            $event->processKey,
            $event->connectorType,
            $event->connectionName,
            $event->documentId,
            $event->documentUuid,
            $event->eventKey,
            $event->externalEventKey,
            $event->occurredAt,
            $event->receivedAt,
            $event->contentType,
            $event->rawPayload,
            $event->normalizedPayloadJson,
            $status,
            $retryCount,
            $lastError,
            $processedAt,
            $event->createdAt,
            $this->dateTimeNormalizer->nowUtc()
        );

        $this->events[(int) $stored->id] = $stored;

        return $stored;
    }
}
