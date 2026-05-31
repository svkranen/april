<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\IncomingEvent;
use App\Intelligence\Domain\DateTimeNormalizer;
use DateTimeImmutable;

final readonly class IncomingEventIntake
{
    public function __construct(
        private IncomingEventStore $incomingEventStore,
        private DateTimeNormalizer $dateTimeNormalizer = new DateTimeNormalizer()
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function accept(array $payload, string $rawPayload, ?string $contentType): IncomingEvent
    {
        return $this->incomingEventStore->save(new IncomingEvent(
            null,
            $this->stringValue($payload, ['process_key', 'processKey'], 'unknown'),
            $this->stringValue($payload, ['connector_type', 'connectorType', 'sourceSystem', 'source_system', 'source'], 'amagno'),
            $this->nullableStringValue($payload, ['connection_name', 'connectionName', 'connection']),
            $this->nullableStringValue($payload, ['document_external_id', 'documentExternalId', 'document_id', 'documentId', 'externalId']),
            $this->nullableStringValue($payload, ['document_uuid', 'documentUuid', 'externalUuid', 'uuid']),
            $this->nullableStringValue($payload, ['event_key', 'eventKey', 'event_type', 'eventType', 'type']),
            $this->nullableStringValue($payload, ['external_event_key', 'externalEventKey', 'event_id', 'eventId', 'id']),
            $this->dateValue($payload, ['occurred_at', 'occurredAt', 'occured_at', 'occuredAt', 'timestamp', 'changeDate']),
            $this->dateTimeNormalizer->nowUtc(),
            $contentType,
            $rawPayload,
            $payload
        ));
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $keys
     */
    private function stringValue(array $payload, array $keys, string $default): string
    {
        foreach ($keys as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key]) && trim((string) $payload[$key]) !== '') {
                return trim((string) $payload[$key]);
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
    private function dateValue(array $payload, array $keys): ?DateTimeImmutable
    {
        $value = $this->nullableStringValue($payload, $keys);
        if ($value === null) {
            return null;
        }

        try {
            return $this->dateTimeNormalizer->parseAmagnoValue($value);
        } catch (\Exception) {
            return null;
        }
    }
}
