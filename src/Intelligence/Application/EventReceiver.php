<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\CanonicalEvent;
use App\Intelligence\Domain\ProcessEvent;
use App\Intelligence\Port\EventNormalizer;
use App\Intelligence\Port\EventStore;
use App\Intelligence\Port\EventStoreResult;
use DateTimeImmutable;
use JsonException;

final class EventReceiver
{
    public function __construct(
        private readonly EventNormalizer $eventNormalizer,
        private readonly EventStore $eventStore,
        private readonly ProcessInstanceManager $processInstanceManager,
        private readonly ContextSnapshotService $contextSnapshotService
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws JsonException
     */
    public function receive(array $payload, string $rawPayload): EventStoreResult
    {
        $canonicalEvent = $this->eventNormalizer->normalize($payload);
        $normalizedJson = json_encode($this->normalizeEvent($canonicalEvent), JSON_THROW_ON_ERROR);

        $event = new ProcessEvent(
            null,
            $this->externalEventKey($payload, $canonicalEvent),
            $canonicalEvent->document->sourceSystem,
            $this->processKey($payload, $canonicalEvent),
            $this->eventKey($payload, $canonicalEvent),
            $canonicalEvent->stepKey,
            $canonicalEvent->document->externalId,
            $canonicalEvent->document->externalUuid,
            $canonicalEvent->document->version,
            $canonicalEvent->actorRef,
            $canonicalEvent->occurredAt,
            new DateTimeImmutable(),
            $rawPayload,
            $normalizedJson
        );

        $result = $this->eventStore->append($event);
        if ($result->duplicate) {
            return $result;
        }

        $instance = $this->processInstanceManager->findOrCreateForEvent($result->event);
        $eventWithInstance = $this->eventStore->attachProcessInstance($result->event, (int) $instance->id);
        $this->contextSnapshotService->captureForEvent($eventWithInstance);

        return new EventStoreResult($eventWithInstance, false);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeEvent(CanonicalEvent $event): array
    {
        return [
            'document' => [
                'sourceSystem' => $event->document->sourceSystem,
                'externalId' => $event->document->externalId,
                'externalUuid' => $event->document->externalUuid,
                'version' => $event->document->version,
            ],
            'stepKey' => $event->stepKey,
            'actorRef' => $event->actorRef,
            'occurredAt' => $event->occurredAt->format(DateTimeImmutable::ATOM),
            'attributes' => $event->attributes,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function externalEventKey(array $payload, CanonicalEvent $event): string
    {
        foreach (['external_event_key', 'externalEventKey', 'event_id', 'eventId', 'id'] as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key]) && (string) $payload[$key] !== '') {
                return (string) $payload[$key];
            }
        }

        return hash('sha256', implode('|', [
            $event->document->sourceSystem,
            $event->document->externalUuid ?? $event->document->externalId,
            (string) $event->document->version,
            $event->stepKey,
            $event->occurredAt->format(DateTimeImmutable::ATOM),
        ]));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function processKey(array $payload, CanonicalEvent $event): string
    {
        foreach (['process_key', 'processKey'] as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key]) && (string) $payload[$key] !== '') {
                return (string) $payload[$key];
            }
        }

        return implode(':', [
            $event->document->sourceSystem,
            $event->document->externalUuid ?? $event->document->externalId,
            'v'.$event->document->version,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function eventKey(array $payload, CanonicalEvent $event): string
    {
        foreach (['event_key', 'eventKey'] as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key]) && (string) $payload[$key] !== '') {
                return (string) $payload[$key];
            }
        }

        return $event->stepKey;
    }
}
