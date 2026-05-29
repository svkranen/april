<?php

namespace App\Tests\Intelligence\Domain;

use App\Intelligence\Domain\CanonicalEvent;
use App\Intelligence\Domain\DocumentRef;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class CanonicalEventTest extends TestCase
{
    public function testStoresCanonicalEventData(): void
    {
        $document = new DocumentRef('amagno', 'doc-123', 'uuid-123', 1);
        $occurredAt = new DateTimeImmutable('2026-05-29T10:00:00+00:00');

        $event = new CanonicalEvent(
            $document,
            'invoice.approved',
            'user-42',
            $occurredAt,
            ['amount' => 12000]
        );

        self::assertSame($document, $event->document);
        self::assertSame('invoice.approved', $event->stepKey);
        self::assertSame('user-42', $event->actorRef);
        self::assertSame($occurredAt, $event->occurredAt);
        self::assertSame(['amount' => 12000], $event->attributes);
    }
}
