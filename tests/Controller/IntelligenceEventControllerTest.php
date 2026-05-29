<?php

namespace App\Tests\Controller;

use App\Controller\IntelligenceEventController;
use App\Intelligence\Application\EventReceiver;
use App\Intelligence\Infrastructure\EventStore\InMemoryEventStore;
use App\Intelligence\Infrastructure\Normalizer\GenericPayloadEventNormalizer;
use App\Tests\Fake\FakeSignatureVerifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class IntelligenceEventControllerTest extends TestCase
{
    public function testPostStoresValidEvent(): void
    {
        $store = new InMemoryEventStore();
        $controller = new IntelligenceEventController(
            new FakeSignatureVerifier(true),
            new EventReceiver(new GenericPayloadEventNormalizer(), $store)
        );

        $response = $controller($this->request($this->payload()));
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(202, $response->getStatusCode());
        self::assertTrue($data['accepted']);
        self::assertFalse($data['duplicate']);
        self::assertSame('evt-controller-1', $data['external_event_key']);
        self::assertSame(1, $store->count());
    }

    public function testInvalidSignatureIsRejected(): void
    {
        $store = new InMemoryEventStore();
        $controller = new IntelligenceEventController(
            new FakeSignatureVerifier(false),
            new EventReceiver(new GenericPayloadEventNormalizer(), $store)
        );

        $response = $controller($this->request($this->payload(), 'bad-signature'));
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(401, $response->getStatusCode());
        self::assertFalse($data['accepted']);
        self::assertSame('invalid_signature', $data['error']);
        self::assertSame(0, $store->count());
    }

    public function testDuplicateEventReturnsDuplicateWithoutSecondAppend(): void
    {
        $store = new InMemoryEventStore();
        $controller = new IntelligenceEventController(
            new FakeSignatureVerifier(true),
            new EventReceiver(new GenericPayloadEventNormalizer(), $store)
        );

        $first = $controller($this->request($this->payload()));
        $second = $controller($this->request($this->payload()));
        $secondData = json_decode((string) $second->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(202, $first->getStatusCode());
        self::assertSame(200, $second->getStatusCode());
        self::assertTrue($secondData['duplicate']);
        self::assertSame(1, $store->count());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function request(array $payload, string $signature = 'valid-signature'): Request
    {
        return Request::create(
            '/api/intelligence/events',
            'POST',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_INTELLIGENCE_SIGNATURE' => $signature,
            ],
            json_encode($payload, JSON_THROW_ON_ERROR)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'external_event_key' => 'evt-controller-1',
            'source_system' => 'amagno',
            'document_external_id' => 'doc-123',
            'document_uuid' => 'uuid-123',
            'document_version' => 1,
            'step_key' => 'invoice.received',
            'occurred_at' => '2026-05-29T10:00:00+00:00',
        ];
    }
}
