<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessEvent;
use App\Intelligence\Domain\ProcessInstance;
use DateTimeImmutable;

final class ProcessInstanceManager
{
    public function __construct(
        private readonly ProcessInstanceRepository $repository
    ) {
    }

    public function findOrCreateForEvent(ProcessEvent $event, string $templateVersion = 'draft'): ProcessInstance
    {
        $instance = $this->repository->findByIdentity(
            $event->sourceSystem,
            $event->documentUuid,
            $event->documentExternalId,
            $event->documentVersion,
            $event->processKey,
            $templateVersion
        );

        if ($instance === null) {
            $now = new DateTimeImmutable();
            $instance = new ProcessInstance(
                null,
                $event->sourceSystem,
                $event->processKey,
                $templateVersion,
                $event->documentExternalId,
                $event->documentUuid,
                $event->documentVersion,
                'running',
                $event->eventPhase === 'after' ? $event->stepKey : 'unknown',
                $event->occurredAt,
                $event->occurredAt,
                null,
                $now,
                $now,
                [$event->externalEventKey]
            );

            return $this->repository->save($instance);
        }

        return $this->repository->save($instance->withEvent($event, new DateTimeImmutable()));
    }
}
