<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\EventReceiver;
use App\Intelligence\Infrastructure\EventStore\InMemoryEventStore;
use App\Intelligence\Infrastructure\Normalizer\GenericPayloadEventNormalizer;
use PHPUnit\Framework\TestCase;

class EventReceiverTest extends TestCase
{
    public function testValidEventIsStoredAppendOnly(): void
    {
        $store = new InMemoryEventStore();
        $receiver = new EventReceiver(new GenericPayloadEventNormalizer(), $store);
        $rawPayload = json_encode($this->payload(), JSON_THROW_ON_ERROR);

        $result = $receiver->receive($this->payload(), $rawPayload);

        self::assertFalse($result->duplicate);
        self::assertSame(1, $store->count());
        self::assertSame('evt-1', $result->event->externalEventKey);
        self::assertSame('amagno:uuid-123:v2', $result->event->processKey);
        self::assertSame($rawPayload, $result->event->rawPayloadJson);
        self::assertSame('invoice.received', $result->event->stepKey);

        $normalized = json_decode($result->event->normalizedEventJson, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('doc-123', $normalized['document']['externalId']);
        self::assertSame('invoice.received', $normalized['stepKey']);
    }

    public function testDuplicateEventIsNotStoredTwice(): void
    {
        $store = new InMemoryEventStore();
        $receiver = new EventReceiver(new GenericPayloadEventNormalizer(), $store);
        $rawPayload = json_encode($this->payload(), JSON_THROW_ON_ERROR);

        $first = $receiver->receive($this->payload(), $rawPayload);
        $second = $receiver->receive($this->payload(), $rawPayload);

        self::assertFalse($first->duplicate);
        self::assertTrue($second->duplicate);
        self::assertSame(1, $store->count());
        self::assertSame($first->event->id, $second->event->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'external_event_key' => 'evt-1',
            'source_system' => 'amagno',
            'document_external_id' => 'doc-123',
            'document_uuid' => 'uuid-123',
            'document_version' => 2,
            'step_key' => 'invoice.received',
            'actor_ref' => 'user-1',
            'occurred_at' => '2026-05-29T10:00:00+00:00',
            'attributes' => [
                'amount' => 12000,
            ],
        ];
    }
}
