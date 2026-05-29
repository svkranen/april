<?php

namespace App\Tests\Intelligence\Infrastructure\Normalizer;

use App\Intelligence\Infrastructure\Normalizer\GenericPayloadEventNormalizer;
use PHPUnit\Framework\TestCase;

class GenericPayloadEventNormalizerTest extends TestCase
{
    public function testCreatesCanonicalEventFromPayload(): void
    {
        $normalizer = new GenericPayloadEventNormalizer();

        $event = $normalizer->normalize([
            'source_system' => 'amagno',
            'document' => [
                'externalId' => 'doc-123',
                'externalUuid' => 'uuid-123',
                'version' => 4,
            ],
            'step_key' => 'invoice.approved',
            'actor_ref' => 'user-42',
            'occurred_at' => '2026-05-29T10:00:00+00:00',
            'attributes' => [
                'amount' => 12000,
            ],
        ]);

        self::assertSame('amagno', $event->document->sourceSystem);
        self::assertSame('doc-123', $event->document->externalId);
        self::assertSame('uuid-123', $event->document->externalUuid);
        self::assertSame(4, $event->document->version);
        self::assertSame('invoice.approved', $event->stepKey);
        self::assertSame('user-42', $event->actorRef);
        self::assertSame('2026-05-29T10:00:00+00:00', $event->occurredAt->format('c'));
        self::assertSame(['amount' => 12000], $event->attributes);
    }
}
