<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessEventRecord;
use App\Intelligence\Domain\ProcessInstance;
use App\Intelligence\Domain\DateTimeNormalizer;

final class ProcessInstanceManager
{
    public function __construct(
        private readonly ProcessInstanceRepository $repository,
        private readonly DateTimeNormalizer $dateTimeNormalizer = new DateTimeNormalizer()
    ) {
    }

    public function findOrCreateForEvent(ProcessEventRecord $event, string $templateVersion = 'draft'): ProcessInstance
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
            $now = $this->dateTimeNormalizer->nowUtc();
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

        return $this->repository->save($instance->withEvent($event, $this->dateTimeNormalizer->nowUtc()));
    }
}
