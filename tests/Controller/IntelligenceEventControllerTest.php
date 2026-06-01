<?php

namespace App\Tests\Controller;

use App\Controller\IntelligenceEventController;
use App\Intelligence\Application\IncomingEventIntake;
use App\Intelligence\Infrastructure\Process\InMemoryIncomingEventStore;
use App\Intelligence\Port\SignatureVerifier;
use App\Tests\Fake\FakeSignatureVerifier;
use Psr\Log\AbstractLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class IntelligenceEventControllerTest extends TestCase
{
    public function testPostStoresValidEvent(): void
    {
        $store = new InMemoryIncomingEventStore();
        $controller = new IntelligenceEventController(
            new FakeSignatureVerifier(true),
            $this->intake($store)
        );

        $response = $controller($this->request($this->payload()));
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($data['accepted']);
        self::assertFalse($data['duplicate']);
        self::assertSame('evt-controller-1', $data['external_event_key']);
        self::assertSame(1, $store->count());
    }

    public function testPostStoresFormUrlEncodedEvent(): void
    {
        $store = new InMemoryIncomingEventStore();
        $controller = new IntelligenceEventController(
            new FakeSignatureVerifier(true),
            $this->intake($store)
        );

        $response = $controller($this->formRequest($this->payload([
            'external_event_key' => 'evt-form-1',
        ])));
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($data['accepted']);
        self::assertSame('evt-form-1', $data['external_event_key']);
        self::assertSame(1, $store->count());
    }

    public function testPostStoresFormUrlEncodedOccurredAtWithEncodedPlusOffset(): void
    {
        $store = new InMemoryIncomingEventStore();
        $controller = new IntelligenceEventController(
            new FakeSignatureVerifier(true),
            $this->intake($store)
        );
        $body = 'externalEventKey=evt-form-plus-1&sourceSystem=amagno&documentId=doc-form-plus'
            .'&documentUuid=uuid-form-plus&documentVersion=1&eventKey=invoice.received'
            .'&eventPhase=after&processKey=invoice&stepKey=invoice.received'
            .'&occurredAt=2026-05-31T18%3A45%3A00%2B00%3A00';

        $response = $controller($this->rawFormRequest($body));
        $events = $store->all();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('2026-05-31T18:45:00+00:00', $events[0]->occurredAt?->format(DATE_ATOM));
    }

    public function testPostStoresFormUrlEncodedOccurredAtWithUnencodedPlusOffset(): void
    {
        $store = new InMemoryIncomingEventStore();
        $controller = new IntelligenceEventController(
            new FakeSignatureVerifier(true),
            $this->intake($store)
        );
        $body = 'externalEventKey=evt-form-plus-2&sourceSystem=amagno&documentId=doc-form-plus'
            .'&documentUuid=uuid-form-plus&documentVersion=1&eventKey=invoice.received'
            .'&eventPhase=after&processKey=invoice&stepKey=invoice.received'
            .'&occurredAt=2026-05-31T18%3A45%3A00+02%3A00';

        $response = $controller($this->rawFormRequest($body));
        $events = $store->all();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('2026-05-31T16:45:00+00:00', $events[0]->occurredAt?->format(DATE_ATOM));
    }

    public function testFormEventWithoutDocumentVersionDefaultsToVersionOne(): void
    {
        $store = new InMemoryIncomingEventStore();
        $controller = new IntelligenceEventController(
            new FakeSignatureVerifier(true),
            $this->intake($store)
        );
        $payload = [
            'externalEventKey' => 'event',
            'sourceSystem' => 'amagno',
            'documentId' => 'doc-form-version-default',
            'documentUuid' => 'uuid-form-version-default',
            'eventKey' => 'received',
            'eventPhase' => 'after',
            'processKey' => 'invoice',
            'stepKey' => 'received',
            'occurredAt' => '2026-05-29T08:00:00+00:00',
        ];

        $response = $controller($this->formRequest($payload));
        $events = $store->all();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('doc-form-version-default', $events[0]->documentId);
        self::assertSame('pending', $events[0]->status);
    }

    public function testGeneratedFormEventKeyTreatsSameOccurredAtAsDuplicate(): void
    {
        $store = new InMemoryIncomingEventStore();
        $controller = new IntelligenceEventController(
            new FakeSignatureVerifier(true),
            $this->intake($store)
        );
        $payload = [
            'sourceSystem' => 'amagno',
            'documentId' => 'doc-form-duplicate',
            'documentUuid' => 'uuid-form-duplicate',
            'eventKey' => 'received',
            'eventPhase' => 'after',
            'processKey' => 'invoice',
            'stepKey' => 'received',
            'occurredAt' => '2026-05-29T08:00:00+00:00',
        ];

        $first = $controller($this->formRequest($payload));
        $second = $controller($this->formRequest($payload));
        $secondData = json_decode((string) $second->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(200, $second->getStatusCode());
        self::assertFalse($secondData['duplicate']);
        self::assertSame(2, $store->count());
    }

    public function testGeneratedFormEventKeyAllowsSameDocumentAndStepWithDifferentOccurredAt(): void
    {
        $store = new InMemoryIncomingEventStore();
        $controller = new IntelligenceEventController(
            new FakeSignatureVerifier(true),
            $this->intake($store)
        );
        $payload = [
            'sourceSystem' => 'amagno',
            'documentId' => 'doc-form-repeat-step',
            'documentUuid' => 'uuid-form-repeat-step',
            'eventKey' => 'approved',
            'eventPhase' => 'after',
            'processKey' => 'invoice',
            'stepKey' => 'approved',
            'occurredAt' => '2026-05-29T08:00:00+00:00',
        ];

        $first = $controller($this->formRequest($payload));
        $second = $controller($this->formRequest(array_replace($payload, [
            'occurredAt' => '2026-05-29T09:00:00+00:00',
        ])));
        $secondData = json_decode((string) $second->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(200, $second->getStatusCode());
        self::assertFalse($secondData['duplicate']);
        self::assertSame(2, $store->count());
    }

    public function testBeforeEventIsStoredButDoesNotUpdateCurrentStepKey(): void
    {
        $store = new InMemoryIncomingEventStore();
        $controller = new IntelligenceEventController(
            new FakeSignatureVerifier(true),
            $this->intake($store)
        );
        $basePayload = [
            'sourceSystem' => 'amagno',
            'documentId' => 'doc-phase-before',
            'documentUuid' => 'uuid-phase-before',
            'eventKey' => 'workflow_step',
            'processKey' => 'invoice',
        ];

        $controller($this->formRequest(array_replace($basePayload, [
            'stepKey' => 'received',
            'eventPhase' => 'after',
            'occurredAt' => '2026-05-29T08:00:00+00:00',
        ])));
        $controller($this->formRequest(array_replace($basePayload, [
            'stepKey' => 'approved',
            'eventPhase' => 'before',
            'occurredAt' => '2026-05-29T09:00:00+00:00',
        ])));

        $events = $store->all();

        self::assertSame(2, $store->count());
        self::assertSame('before', $events[1]->normalizedPayloadJson['eventPhase']);
        self::assertSame('pending', $events[1]->status);
    }

    public function testAfterEventUpdatesCurrentStepKey(): void
    {
        $store = new InMemoryIncomingEventStore();
        $controller = new IntelligenceEventController(
            new FakeSignatureVerifier(true),
            $this->intake($store)
        );
        $basePayload = [
            'sourceSystem' => 'amagno',
            'documentId' => 'doc-phase-after',
            'documentUuid' => 'uuid-phase-after',
            'eventKey' => 'workflow_step',
            'processKey' => 'invoice',
        ];

        $controller($this->formRequest(array_replace($basePayload, [
            'stepKey' => 'received',
            'eventPhase' => 'after',
            'occurredAt' => '2026-05-29T08:00:00+00:00',
        ])));
        $controller($this->formRequest(array_replace($basePayload, [
            'stepKey' => 'approved',
            'eventPhase' => 'after',
            'occurredAt' => '2026-05-29T09:00:00+00:00',
        ])));

        $events = $store->all();

        self::assertSame('after', $events[1]->normalizedPayloadJson['eventPhase']);
        self::assertSame('pending', $events[1]->status);
    }

    public function testInvalidOccurredAtReturnsValidationError(): void
    {
        $store = new InMemoryIncomingEventStore();
        $controller = new IntelligenceEventController(
            new FakeSignatureVerifier(true),
            $this->intake($store)
        );
        $payload = $this->payload([
            'externalEventKey' => 'evt-invalid-date-1',
            'occurredAt' => 'not-a-date',
        ]);

        $response = $controller($this->request($payload));
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($data['accepted']);
        self::assertSame(1, $store->count());
    }

    public function testMinimalAmagnoPayloadIsAcceptedAndExternalKeyIsGenerated(): void
    {
        $store = new InMemoryIncomingEventStore();
        $controller = new IntelligenceEventController(
            new FakeSignatureVerifier(true),
            $this->intake($store)
        );
        $payload = [
            'sourceSystem' => 'amagno',
            'documentId' => 'doc-only-123',
            'documentUuid' => 'uuid-only-123',
            'eventKey' => 'approved',
            'eventPhase' => 'after',
            'processKey' => 'invoice',
            'stepKey' => 'approved',
            'occurredAt' => '2026-05-29T12:00:00+00:00',
        ];

        $first = $controller($this->request($payload));
        $second = $controller($this->request($payload));
        $firstData = json_decode((string) $first->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $secondData = json_decode((string) $second->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $events = $store->all();

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(200, $second->getStatusCode());
        self::assertSame($firstData['external_event_key'], $secondData['external_event_key']);
        self::assertSame('doc-only-123', $events[0]->documentId);
        self::assertSame('uuid-only-123', $events[0]->documentUuid);
        self::assertSame(2, $store->count());
    }

    public function testMissingRequiredFieldReturnsValidationError(): void
    {
        $store = new InMemoryIncomingEventStore();
        $controller = new IntelligenceEventController(
            new FakeSignatureVerifier(true),
            $this->intake($store)
        );
        $payload = $this->payload();
        unset($payload['documentUuid']);

        $response = $controller($this->request($payload));
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($data['accepted']);
        self::assertSame(1, $store->count());
    }

    public function testUnknownProcessKeyReturnsValidationError(): void
    {
        $store = new InMemoryIncomingEventStore();
        $controller = new IntelligenceEventController(
            new FakeSignatureVerifier(true),
            $this->intake($store)
        );

        $response = $controller($this->request($this->payload(['processKey' => 'unknown'])));
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(400, $response->getStatusCode());
        self::assertFalse($data['accepted']);
        self::assertSame('unknown_process_key', $data['error']);
        self::assertSame('processKey', $data['field']);
    }

    public function testEmptyStepKeyReturnsValidationError(): void
    {
        $store = new InMemoryIncomingEventStore();
        $controller = new IntelligenceEventController(
            new FakeSignatureVerifier(true),
            $this->intake($store)
        );

        $response = $controller($this->request($this->payload(['stepKey' => ''])));
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($data['accepted']);
    }

    public function testInvalidEventPhaseReturnsValidationError(): void
    {
        $store = new InMemoryIncomingEventStore();
        $controller = new IntelligenceEventController(
            new FakeSignatureVerifier(true),
            $this->intake($store)
        );

        $response = $controller($this->request($this->payload(['eventPhase' => 'during'])));
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($data['accepted']);
    }

    public function testApiKeyCanBeProvidedAsFormOrQueryParameter(): void
    {
        $formVerifier = new class implements SignatureVerifier {
            public ?string $signature = null;

            public function verify(string $payload, string $signature): bool
            {
                $this->signature = $signature;

                return $signature !== '';
            }
        };
        $formStore = new InMemoryIncomingEventStore();
        $formController = new IntelligenceEventController($formVerifier, $this->intake($formStore));

        $formResponse = $formController($this->formRequest($this->payload([
            'external_event_key' => 'evt-form-api-key',
            'apiKey' => 'form-secret',
        ]), null));

        $queryVerifier = new class implements SignatureVerifier {
            public ?string $signature = null;

            public function verify(string $payload, string $signature): bool
            {
                $this->signature = $signature;

                return $signature !== '';
            }
        };
        $queryStore = new InMemoryIncomingEventStore();
        $queryController = new IntelligenceEventController($queryVerifier, $this->intake($queryStore));
        $queryRequest = $this->request($this->payload([
            'external_event_key' => 'evt-query-api-key',
        ]), null);
        $queryRequest->query->set('apiKey', 'query-secret');

        $queryResponse = $queryController($queryRequest);

        self::assertSame(200, $formResponse->getStatusCode());
        self::assertSame('form-secret', $formVerifier->signature);
        self::assertSame(200, $queryResponse->getStatusCode());
        self::assertSame('query-secret', $queryVerifier->signature);
    }

    public function testInvalidSignatureIsRejected(): void
    {
        $store = new InMemoryIncomingEventStore();
        $controller = new IntelligenceEventController(
            new FakeSignatureVerifier(false),
            $this->intake($store)
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
        $store = new InMemoryIncomingEventStore();
        $controller = new IntelligenceEventController(
            new FakeSignatureVerifier(true),
            $this->intake($store)
        );

        $first = $controller($this->request($this->payload()));
        $second = $controller($this->request($this->payload()));
        $secondData = json_decode((string) $second->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(200, $second->getStatusCode());
        self::assertFalse($secondData['duplicate']);
        self::assertSame(2, $store->count());
    }

    public function testPostDebugLogsRequestWithoutHeaderSecrets(): void
    {
        $store = new InMemoryIncomingEventStore();
        $logger = new class extends AbstractLogger {
            /** @var array<int, array{level: mixed, message: string, context: array<string, mixed>}> */
            public array $records = [];

            /**
             * @param array<string, mixed> $context
             */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };
        $controller = new IntelligenceEventController(
            new FakeSignatureVerifier(true),
            $this->intake($store),
            $logger
        );
        $request = $this->request($this->payload([
            'apiKey' => 'json-secret-api-key',
        ]));
        $request->query->set('debug', '1');
        $request->query->set('apiKey', 'query-secret-api-key');
        $request->request->set('form_field', 'form-value');
        $request->request->set('apiKey', 'form-secret-api-key');
        $request->headers->set('X-Api-Key', 'secret-api-key');
        $request->headers->set('Authorization', 'Bearer secret-token');

        $controller($request);

        self::assertCount(1, $logger->records);
        $context = $logger->records[0]['context'];
        self::assertSame('application/json', $context['content_type']);
        self::assertStringContainsString('evt-controller-1', $context['raw_body']);
        self::assertSame(['debug' => '1', 'apiKey' => 'present'], $context['query_parameters']);
        self::assertSame(['form_field' => 'form-value', 'apiKey' => 'present'], $context['request_parameters']);
        self::assertSame('present', $context['headers']['x-api-key']);
        self::assertSame('present', $context['headers']['authorization']);
        self::assertSame('present', $context['headers']['x-intelligence-signature']);
        self::assertStringNotContainsString('secret-api-key', json_encode($context['headers'], JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('secret-token', json_encode($context['headers'], JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('valid-signature', json_encode($context['headers'], JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('query-secret-api-key', json_encode($context, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('form-secret-api-key', json_encode($context, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('json-secret-api-key', json_encode($context, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function request(array $payload, ?string $signature = 'valid-signature'): Request
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
        ];
        if ($signature !== null) {
            $server['HTTP_X_INTELLIGENCE_SIGNATURE'] = $signature;
        }

        return Request::create(
            '/api/intelligence/events',
            'POST',
            [],
            [],
            [],
            $server,
            json_encode($payload, JSON_THROW_ON_ERROR)
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function formRequest(array $payload, ?string $signature = 'valid-signature'): Request
    {
        $server = [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ];
        if ($signature !== null) {
            $server['HTTP_X_INTELLIGENCE_SIGNATURE'] = $signature;
        }

        return Request::create(
            '/api/intelligence/events',
            'POST',
            $payload,
            [],
            [],
            $server
        );
    }

    private function rawFormRequest(string $body, ?string $signature = 'valid-signature'): Request
    {
        parse_str($body, $payload);

        $server = [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ];
        if ($signature !== null) {
            $server['HTTP_X_INTELLIGENCE_SIGNATURE'] = $signature;
        }

        return Request::create(
            '/api/intelligence/events',
            'POST',
            $payload,
            [],
            [],
            $server,
            $body
        );
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'externalEventKey' => 'evt-controller-1',
            'sourceSystem' => 'amagno',
            'documentId' => 'doc-123',
            'documentUuid' => 'uuid-123',
            'documentVersion' => 1,
            'eventKey' => 'invoice.received',
            'eventPhase' => 'after',
            'processKey' => 'invoice',
            'stepKey' => 'invoice.received',
            'occurredAt' => '2026-05-29T10:00:00+00:00',
        ], $overrides);
    }

    private function intake(InMemoryIncomingEventStore $store): IncomingEventIntake
    {
        return new IncomingEventIntake($store);
    }
}
