<?php

namespace App\Tests\Fake;

use App\Intelligence\Domain\CanonicalEvent;
use App\Intelligence\Domain\DocumentRef;
use App\Intelligence\Port\EventNormalizer;
use DateTimeImmutable;

final class FakeEventNormalizer implements EventNormalizer
{
    public function normalize(array $payload): CanonicalEvent
    {
        return new CanonicalEvent(
            new DocumentRef(
                (string) ($payload['source_system'] ?? 'test'),
                (string) ($payload['external_id'] ?? ''),
                isset($payload['external_uuid']) ? (string) $payload['external_uuid'] : null,
                (int) ($payload['version'] ?? 1)
            ),
            (string) ($payload['step_key'] ?? ''),
            isset($payload['actor_ref']) ? (string) $payload['actor_ref'] : null,
            new DateTimeImmutable((string) ($payload['occurred_at'] ?? 'now')),
            (string) ($payload['event_phase'] ?? 'after'),
            is_array($payload['attributes'] ?? null) ? $payload['attributes'] : []
        );
    }
}
