<?php

namespace App\Tests\Intelligence\Domain;

use App\Intelligence\Domain\ProcessEvent;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class ProcessEventTest extends TestCase
{
    public function testStoresOnlyBusinessProcessInformation(): void
    {
        $occurredAt = new DateTimeImmutable('2026-05-29T10:00:00+00:00');

        $event = new ProcessEvent(
            'invoice-approval',
            'document',
            'uuid-123',
            'invoice.approved',
            $occurredAt,
            ['amount' => 12000]
        );

        self::assertSame('invoice-approval', $event->processKey);
        self::assertSame('document', $event->entityType);
        self::assertSame('uuid-123', $event->entityId);
        self::assertSame('invoice.approved', $event->eventKey);
        self::assertSame($occurredAt, $event->occurredAt);
        self::assertSame(['amount' => 12000], $event->metadata);
    }
}
