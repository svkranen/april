<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\ProcessInstanceManager;
use App\Intelligence\Domain\ProcessEvent;
use App\Intelligence\Infrastructure\Process\InMemoryProcessInstanceRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class ProcessInstanceManagerTest extends TestCase
{
    public function testFirstEventCreatesProcessInstance(): void
    {
        $repository = new InMemoryProcessInstanceRepository();
        $manager = new ProcessInstanceManager($repository);
        $event = $this->event('evt-1', 1, 'received');

        $instance = $manager->findOrCreateForEvent($event, 'draft');

        self::assertSame(1, $repository->count());
        self::assertSame(1, $instance->id);
        self::assertSame('amagno', $instance->sourceSystem);
        self::assertSame('process-invoice', $instance->processKey);
        self::assertSame('draft', $instance->templateVersion);
        self::assertSame('doc-123', $instance->documentExternalId);
        self::assertSame('uuid-123', $instance->documentUuid);
        self::assertSame(1, $instance->documentVersion);
        self::assertSame('running', $instance->status);
        self::assertSame('received', $instance->currentStepKey);
        self::assertSame($event->occurredAt, $instance->startedAt);
        self::assertSame($event->occurredAt, $instance->lastEventAt);
        self::assertNull($instance->endedAt);
        self::assertSame(['evt-1'], $instance->eventExternalKeys);
    }

    public function testSecondEventSameVersionUsesSameInstance(): void
    {
        $repository = new InMemoryProcessInstanceRepository();
        $manager = new ProcessInstanceManager($repository);

        $first = $manager->findOrCreateForEvent($this->event('evt-1', 1, 'received'));
        $secondEvent = $this->event('evt-2', 1, 'approved', '2026-05-29T11:00:00+00:00');
        $second = $manager->findOrCreateForEvent($secondEvent);

        self::assertSame(1, $repository->count());
        self::assertSame($first->id, $second->id);
        self::assertSame('approved', $second->currentStepKey);
        self::assertSame($secondEvent->occurredAt, $second->lastEventAt);
        self::assertSame(['evt-1', 'evt-2'], $second->eventExternalKeys);
    }

    public function testNewDocumentVersionCreatesNewInstance(): void
    {
        $repository = new InMemoryProcessInstanceRepository();
        $manager = new ProcessInstanceManager($repository);

        $versionOne = $manager->findOrCreateForEvent($this->event('evt-v1', 1, 'received'));
        $versionTwo = $manager->findOrCreateForEvent($this->event('evt-v2', 2, 'received'));

        self::assertSame(2, $repository->count());
        self::assertNotSame($versionOne->id, $versionTwo->id);
        self::assertSame(1, $versionOne->documentVersion);
        self::assertSame(2, $versionTwo->documentVersion);
        self::assertSame(['evt-v1'], $versionOne->eventExternalKeys);
        self::assertSame(['evt-v2'], $versionTwo->eventExternalKeys);
    }

    public function testEventsAreAssignedToCorrectInstance(): void
    {
        $repository = new InMemoryProcessInstanceRepository();
        $manager = new ProcessInstanceManager($repository);

        $manager->findOrCreateForEvent($this->event('evt-v1-a', 1, 'received'));
        $manager->findOrCreateForEvent($this->event('evt-v2-a', 2, 'received'));
        $versionOne = $manager->findOrCreateForEvent($this->event('evt-v1-b', 1, 'checked'));
        $versionTwo = $manager->findOrCreateForEvent($this->event('evt-v2-b', 2, 'approved'));

        self::assertSame(2, $repository->count());
        self::assertSame(['evt-v1-a', 'evt-v1-b'], $versionOne->eventExternalKeys);
        self::assertSame(['evt-v2-a', 'evt-v2-b'], $versionTwo->eventExternalKeys);
        self::assertSame('checked', $versionOne->currentStepKey);
        self::assertSame('approved', $versionTwo->currentStepKey);
    }

    private function event(
        string $externalEventKey,
        int $documentVersion,
        string $stepKey,
        string $occurredAt = '2026-05-29T10:00:00+00:00'
    ): ProcessEvent {
        return new ProcessEvent(
            null,
            $externalEventKey,
            'amagno',
            'process-invoice',
            $stepKey,
            $stepKey,
            'doc-123',
            'uuid-123',
            $documentVersion,
            'user-1',
            new DateTimeImmutable($occurredAt),
            new DateTimeImmutable('2026-05-29T12:00:00+00:00'),
            '{}',
            '{}'
        );
    }
}
