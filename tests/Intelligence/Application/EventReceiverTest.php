<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\EventReceiver;
use App\Intelligence\Application\ContextSnapshotService;
use App\Intelligence\Application\ProcessInstanceManager;
use App\Intelligence\Infrastructure\Context\InMemoryContextProfileProvider;
use App\Intelligence\Infrastructure\Context\InMemoryContextSnapshotStore;
use App\Intelligence\Infrastructure\EventStore\InMemoryEventStore;
use App\Intelligence\Infrastructure\Normalizer\GenericPayloadEventNormalizer;
use App\Intelligence\Infrastructure\Process\InMemoryProcessInstanceRepository;
use App\Tests\Fake\RecordingContextProvider;
use PHPUnit\Framework\TestCase;

class EventReceiverTest extends TestCase
{
    public function testValidEventIsStoredAppendOnly(): void
    {
        $store = new InMemoryEventStore();
        $repository = new InMemoryProcessInstanceRepository();
        $snapshotStore = new InMemoryContextSnapshotStore();
        $receiver = $this->receiver($store, $repository, snapshotStore: $snapshotStore);
        $rawPayload = json_encode($this->payload(), JSON_THROW_ON_ERROR);

        $result = $receiver->receive($this->payload(), $rawPayload);

        self::assertFalse($result->duplicate);
        self::assertSame(1, $store->count());
        self::assertSame('evt-1', $result->event->externalEventKey);
        self::assertSame('amagno:uuid-123:v2', $result->event->processKey);
        self::assertSame($rawPayload, $result->event->rawPayloadJson);
        self::assertSame('invoice.received', $result->event->stepKey);
        self::assertSame(1, $result->event->processInstanceId);
        self::assertSame(1, $repository->count());
        self::assertSame(1, $snapshotStore->count());

        $normalized = json_decode($result->event->normalizedEventJson, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('doc-123', $normalized['document']['externalId']);
        self::assertSame('invoice.received', $normalized['stepKey']);
    }

    public function testDuplicateEventIsNotStoredTwice(): void
    {
        $store = new InMemoryEventStore();
        $snapshotStore = new InMemoryContextSnapshotStore();
        $receiver = $this->receiver($store, new InMemoryProcessInstanceRepository(), snapshotStore: $snapshotStore);
        $rawPayload = json_encode($this->payload(), JSON_THROW_ON_ERROR);

        $first = $receiver->receive($this->payload(), $rawPayload);
        $second = $receiver->receive($this->payload(), $rawPayload);

        self::assertFalse($first->duplicate);
        self::assertTrue($second->duplicate);
        self::assertSame(1, $store->count());
        self::assertSame($first->event->id, $second->event->id);
        self::assertSame(1, $snapshotStore->count());
    }

    public function testEventVersionOneCreatesProcessInstanceVersionOne(): void
    {
        $store = new InMemoryEventStore();
        $repository = new InMemoryProcessInstanceRepository();
        $receiver = $this->receiver($store, $repository);

        $receiver->receive($this->payload('evt-v1-a', 1, 'received'), json_encode($this->payload('evt-v1-a', 1, 'received'), JSON_THROW_ON_ERROR));

        $instances = $repository->all();
        self::assertSame(1, $repository->count());
        self::assertSame(1, $instances[0]->documentVersion);
        self::assertSame(['evt-v1-a'], $instances[0]->eventExternalKeys);
    }

    public function testSecondEventSameVersionUsesSameProcessInstance(): void
    {
        $store = new InMemoryEventStore();
        $repository = new InMemoryProcessInstanceRepository();
        $receiver = $this->receiver($store, $repository);

        $receiver->receive($this->payload('evt-v1-a', 1, 'received'), json_encode($this->payload('evt-v1-a', 1, 'received'), JSON_THROW_ON_ERROR));
        $result = $receiver->receive($this->payload('evt-v1-b', 1, 'approved'), json_encode($this->payload('evt-v1-b', 1, 'approved'), JSON_THROW_ON_ERROR));

        $instances = $repository->all();
        self::assertSame(1, $repository->count());
        self::assertSame(1, $result->event->processInstanceId);
        self::assertSame('approved', $instances[0]->currentStepKey);
        self::assertSame(['evt-v1-a', 'evt-v1-b'], $instances[0]->eventExternalKeys);
    }

    public function testEventVersionTwoCreatesNewProcessInstance(): void
    {
        $store = new InMemoryEventStore();
        $repository = new InMemoryProcessInstanceRepository();
        $receiver = $this->receiver($store, $repository);

        $first = $receiver->receive($this->payload('evt-v1-a', 1, 'received'), json_encode($this->payload('evt-v1-a', 1, 'received'), JSON_THROW_ON_ERROR));
        $second = $receiver->receive($this->payload('evt-v2-a', 2, 'received'), json_encode($this->payload('evt-v2-a', 2, 'received'), JSON_THROW_ON_ERROR));

        self::assertSame(2, $repository->count());
        self::assertSame(1, $first->event->processInstanceId);
        self::assertSame(2, $second->event->processInstanceId);
        self::assertSame([1, 2], array_map(static fn ($instance): int => $instance->documentVersion, $repository->all()));
    }

    public function testDuplicateEventDoesNotCreateNewProcessInstance(): void
    {
        $store = new InMemoryEventStore();
        $repository = new InMemoryProcessInstanceRepository();
        $receiver = $this->receiver($store, $repository);
        $payload = $this->payload('evt-v1-a', 1, 'received');
        $rawPayload = json_encode($payload, JSON_THROW_ON_ERROR);

        $first = $receiver->receive($payload, $rawPayload);
        $second = $receiver->receive($payload, $rawPayload);

        self::assertFalse($first->duplicate);
        self::assertTrue($second->duplicate);
        self::assertSame(1, $store->count());
        self::assertSame(1, $repository->count());
    }

    public function testNewEventCapturesContextSnapshotFromProfile(): void
    {
        $store = new InMemoryEventStore();
        $repository = new InMemoryProcessInstanceRepository();
        $snapshotStore = new InMemoryContextSnapshotStore();
        $contextProvider = new RecordingContextProvider([
            'amount' => 12000,
            'documentType' => 'Invoice',
        ]);
        $receiver = $this->receiver(
            $store,
            $repository,
            ['amagno:uuid-123:v2' => ['amount', 'documentType']],
            $contextProvider,
            $snapshotStore
        );

        $payload = $this->payload();
        $receiver->receive($payload, json_encode($payload, JSON_THROW_ON_ERROR));

        $snapshots = $snapshotStore->all();
        self::assertSame(1, $contextProvider->calls);
        self::assertSame(['amount', 'documentType'], $contextProvider->lastFields);
        self::assertSame('doc-123', $contextProvider->lastDocument?->externalId);
        self::assertSame([
            'amount' => 12000,
            'documentType' => 'Invoice',
        ], $snapshots[0]->attributes);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $externalEventKey = 'evt-1', int $documentVersion = 2, string $stepKey = 'invoice.received'): array
    {
        return [
            'external_event_key' => $externalEventKey,
            'source_system' => 'amagno',
            'document_external_id' => 'doc-123',
            'document_uuid' => 'uuid-123',
            'document_version' => $documentVersion,
            'step_key' => $stepKey,
            'actor_ref' => 'user-1',
            'occurred_at' => '2026-05-29T10:00:00+00:00',
            'attributes' => [
                'amount' => 12000,
            ],
        ];
    }

    /**
     * @param array<string, array<int, string>> $profiles
     */
    private function receiver(
        InMemoryEventStore $store,
        InMemoryProcessInstanceRepository $repository,
        array $profiles = [],
        ?RecordingContextProvider $contextProvider = null,
        ?InMemoryContextSnapshotStore $snapshotStore = null
    ): EventReceiver
    {
        $snapshotStore ??= new InMemoryContextSnapshotStore();
        $contextProvider ??= new RecordingContextProvider([]);

        return new EventReceiver(
            new GenericPayloadEventNormalizer(),
            $store,
            new ProcessInstanceManager($repository),
            new ContextSnapshotService(
                new InMemoryContextProfileProvider($profiles),
                $contextProvider,
                $snapshotStore
            )
        );
    }
}
