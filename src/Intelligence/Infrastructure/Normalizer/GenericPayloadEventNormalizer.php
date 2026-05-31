<?php

namespace App\Intelligence\Infrastructure\Normalizer;

use App\Intelligence\Domain\CanonicalEvent;
use App\Intelligence\Domain\DocumentRef;
use App\Intelligence\Domain\DateTimeNormalizer;
use App\Intelligence\Port\EventNormalizer;

final class GenericPayloadEventNormalizer implements EventNormalizer
{
    public function __construct(
        private readonly DateTimeNormalizer $dateTimeNormalizer = new DateTimeNormalizer()
    ) {
    }

    public function normalize(array $payload): CanonicalEvent
    {
        $document = is_array($payload['document'] ?? null) ? $payload['document'] : [];

        return new CanonicalEvent(
            new DocumentRef(
                $this->stringValue($payload, ['source_system', 'sourceSystem', 'source'], 'amagno'),
                $this->stringValue($payload + $document, ['document_external_id', 'documentExternalId', 'document_id', 'documentId', 'externalId', 'id'], ''),
                $this->nullableStringValue($payload + $document, ['document_uuid', 'documentUuid', 'externalUuid', 'uuid']),
                $this->intValue($payload + $document, ['document_version', 'documentVersion', 'version'], 1)
            ),
            $this->stringValue($payload, ['step_key', 'stepKey', 'event_key', 'eventKey', 'event_type', 'eventType', 'type'], 'unknown'),
            $this->nullableStringValue($payload, ['actor_ref', 'actorRef', 'actor', 'user']),
            $this->dateTimeNormalizer->parseAmagnoValue($this->stringValue($payload, ['occurred_at', 'occurredAt', 'occured_at', 'occuredAt', 'timestamp', 'changeDate'], 'now')),
            $this->eventPhase($payload),
            is_array($payload['attributes'] ?? null) ? $payload['attributes'] : []
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $keys
     */
    private function stringValue(array $payload, array $keys, string $default): string
    {
        foreach ($keys as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key]) && (string) $payload[$key] !== '') {
                return (string) $payload[$key];
            }
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $keys
     */
    private function nullableStringValue(array $payload, array $keys): ?string
    {
        $value = $this->stringValue($payload, $keys, '');

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $keys
     */
    private function intValue(array $payload, array $keys, int $default): int
    {
        foreach ($keys as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) {
                return (int) $payload[$key];
            }
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function eventPhase(array $payload): string
    {
        $phase = strtolower($this->stringValue($payload, ['event_phase', 'eventPhase', 'phase'], 'after'));

        return in_array($phase, ['before', 'after', 'unknown'], true) ? $phase : 'unknown';
    }
}
