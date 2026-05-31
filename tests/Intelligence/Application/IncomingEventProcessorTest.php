<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\ContextSnapshotService;
use App\Intelligence\Application\EventReceiver;
use App\Intelligence\Application\IncomingEventProcessor;
use App\Intelligence\Application\ProcessInstanceManager;
use App\Intelligence\Domain\DocumentRef;
use App\Intelligence\Domain\IncomingEvent;
use App\Intelligence\Infrastructure\Context\InMemoryContextProfileProvider;
use App\Intelligence\Infrastructure\Context\InMemoryContextSnapshotStore;
use App\Intelligence\Infrastructure\EventStore\InMemoryEventStore;
use App\Intelligence\Infrastructure\Normalizer\GenericPayloadEventNormalizer;
use App\Intelligence\Infrastructure\Process\InMemoryIncomingEventStore;
use App\Intelligence\Infrastructure\Process\InMemoryProcessInstanceRepository;
use App\Intelligence\Port\ContextProvider;
use App\Tests\Fake\RecordingContextProvider;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class IncomingEventProcessorTest extends TestCase
{
    public function testWorkerProcessesPendingEventSuccessfully(): void
    {
        $incomingStore = new InMemoryIncomingEventStore();
        $eventStore = new InMemoryEventStore();
        $snapshotStore = new InMemoryContextSnapshotStore();
        $contextProvider = new RecordingContextProvider(['amount' => 12000]);
        $incoming = $incomingStore->save($this->incomingEvent());

        $processor = new IncomingEventProcessor(
            $incomingStore,
            $this->receiver($eventStore, $snapshotStore, $contextProvider, ['invoice' => ['amount']])
        );

        $result = $processor->processPending();
        $events = $incomingStore->all();
        $snapshots = $snapshotStore->all();

        self::assertSame(1, $result->processed);
        self::assertSame(0, $result->failed);
        self::assertSame('processed', $events[0]->status);
        self::assertSame(1, $eventStore->count());
        self::assertSame(1, $contextProvider->calls);
        self::assertSame($incoming->id, $snapshots[0]->incomingEventId);
    }

    public function testContextLoadingFailureMarksIncomingEventFailedAndRetries(): void
    {
        $incomingStore = new InMemoryIncomingEventStore();
        $eventStore = new InMemoryEventStore();
        $snapshotStore = new InMemoryContextSnapshotStore();
        $incomingStore->save($this->incomingEvent('evt-fail-1'));

        $processor = new IncomingEventProcessor(
            $incomingStore,
            $this->receiver($eventStore, $snapshotStore, new class implements ContextProvider {
                public function loadAttributes(DocumentRef $document, array $fields): array
                {
                    throw new \RuntimeException('Amagno context unavailable');
                }
            }, ['invoice' => ['amount']])
        );

        $result = $processor->processPending(maxRetries: 5);
        $events = $incomingStore->all();

        self::assertSame(0, $result->processed);
        self::assertSame(1, $result->failed);
        self::assertSame('failed', $events[0]->status);
        self::assertSame(1, $events[0]->retryCount);
        self::assertSame('Amagno context unavailable', $events[0]->lastError);
    }

    public function testMaxRetriesMarksIncomingEventDead(): void
    {
        $incomingStore = new InMemoryIncomingEventStore();
        $eventStore = new InMemoryEventStore();
        $snapshotStore = new InMemoryContextSnapshotStore();
        $incomingStore->save($this->incomingEvent('evt-dead-1'));

        $processor = new IncomingEventProcessor(
            $incomingStore,
            $this->receiver($eventStore, $snapshotStore, new class implements ContextProvider {
                public function loadAttributes(DocumentRef $document, array $fields): array
                {
                    throw new \RuntimeException('still unavailable');
                }
            }, ['invoice' => ['amount']])
        );

        $result = $processor->processPending(maxRetries: 1);
        $events = $incomingStore->all();

        self::assertSame(0, $result->failed);
        self::assertSame(1, $result->dead);
        self::assertSame('dead', $events[0]->status);
        self::assertSame(1, $events[0]->retryCount);
    }

    private function incomingEvent(string $externalEventKey = 'evt-worker-1'): IncomingEvent
    {
        $payload = [
            'externalEventKey' => $externalEventKey,
            'sourceSystem' => 'amagno',
            'documentId' => 'doc-1',
            'documentUuid' => 'uuid-1',
            'documentVersion' => 1,
            'eventKey' => 'invoice_checked',
            'eventPhase' => 'after',
            'processKey' => 'invoice',
            'stepKey' => 'invoice_checked',
            'occurredAt' => '2026-05-29T09:00:00+00:00',
        ];

        return new IncomingEvent(
            null,
            'invoice',
            'amagno',
            'default',
            'doc-1',
            'uuid-1',
            'invoice_checked',
            $externalEventKey,
            new DateTimeImmutable('2026-05-29T09:00:00+00:00'),
            new DateTimeImmutable('2026-05-29T09:00:01+00:00'),
            'application/json',
            json_encode($payload, JSON_THROW_ON_ERROR),
            $payload
        );
    }

    /**
     * @param array<string, array<int, string>> $profiles
     */
    private function receiver(InMemoryEventStore $eventStore, InMemoryContextSnapshotStore $snapshotStore, ContextProvider $contextProvider, array $profiles): EventReceiver
    {
        return new EventReceiver(
            new GenericPayloadEventNormalizer(),
            $eventStore,
            new ProcessInstanceManager(new InMemoryProcessInstanceRepository()),
            new ContextSnapshotService(
                new InMemoryContextProfileProvider($profiles),
                $contextProvider,
                $snapshotStore
            )
        );
    }
}
