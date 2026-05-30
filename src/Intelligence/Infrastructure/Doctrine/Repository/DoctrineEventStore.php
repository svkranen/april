<?php

namespace App\Intelligence\Infrastructure\Doctrine\Repository;

use App\Intelligence\Domain\ProcessEventRecord;
use App\Intelligence\Infrastructure\Doctrine\Entity\ProcessEventEntity;
use App\Intelligence\Infrastructure\Doctrine\Entity\ProcessInstanceEntity;
use App\Intelligence\Port\EventStore;
use App\Intelligence\Port\EventStoreResult;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineEventStore implements EventStore
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    public function append(ProcessEventRecord $event): EventStoreResult
    {
        $existing = $this->entityManager->getRepository(ProcessEventEntity::class)->findOneBy(['externalEventKey' => $event->externalEventKey]);
        if ($existing instanceof ProcessEventEntity) {
            return new EventStoreResult($this->toDomain($existing), true);
        }

        $entity = $this->toEntity($event);
        $this->entityManager->persist($entity);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            $this->entityManager->clear();
            $existing = $this->entityManager->getRepository(ProcessEventEntity::class)->findOneBy(['externalEventKey' => $event->externalEventKey]);
            if ($existing instanceof ProcessEventEntity) {
                return new EventStoreResult($this->toDomain($existing), true);
            }

            throw $exception;
        }

        return new EventStoreResult($this->toDomain($entity), false);
    }

    public function attachProcessInstance(ProcessEventRecord $event, int $processInstanceId): ProcessEventRecord
    {
        $entity = $this->entityManager->getRepository(ProcessEventEntity::class)->findOneBy(['externalEventKey' => $event->externalEventKey]);
        if (!$entity instanceof ProcessEventEntity) {
            return $event->withProcessInstanceId($processInstanceId);
        }

        $entity->setProcessInstance($this->entityManager->getReference(ProcessInstanceEntity::class, $processInstanceId));
        $this->entityManager->flush();

        return $this->toDomain($entity);
    }

    public function count(): int
    {
        return $this->entityManager->getRepository(ProcessEventEntity::class)->count([]);
    }

    private function toEntity(ProcessEventRecord $event): ProcessEventEntity
    {
        $entity = new ProcessEventEntity();
        $entity->setExternalEventKey($event->externalEventKey);
        $entity->setSourceSystem($event->sourceSystem);
        $entity->setProcessKey($event->processKey);
        $entity->setEventKey($event->eventKey);
        $entity->setStepKey($event->stepKey);
        $entity->setEventPhase($event->eventPhase);
        $entity->setDocumentExternalId($event->documentExternalId);
        $entity->setDocumentUuid($event->documentUuid);
        $entity->setDocumentVersion($event->documentVersion);
        $entity->setActorRef($event->actorRef);
        $entity->setOccurredAt($event->occurredAt);
        $entity->setReceivedAt($event->receivedAt);
        $entity->setRawEventJson($this->decodeJson($event->rawPayloadJson));
        $entity->setNormalizedEventJson($this->decodeJson($event->normalizedEventJson));
        if ($event->processInstanceId !== null) {
            $entity->setProcessInstance($this->entityManager->getReference(ProcessInstanceEntity::class, $event->processInstanceId));
        }

        return $entity;
    }

    private function toDomain(ProcessEventEntity $entity): ProcessEventRecord
    {
        return new ProcessEventRecord(
            $entity->getId(),
            $entity->getExternalEventKey(),
            $entity->getSourceSystem(),
            $entity->getProcessKey(),
            $entity->getEventKey(),
            $entity->getStepKey(),
            $entity->getDocumentExternalId(),
            $entity->getDocumentUuid(),
            $entity->getDocumentVersion(),
            $entity->getActorRef(),
            $entity->getOccurredAt(),
            $entity->getReceivedAt(),
            json_encode($entity->getRawEventJson(), JSON_THROW_ON_ERROR),
            json_encode($entity->getNormalizedEventJson(), JSON_THROW_ON_ERROR),
            $entity->getProcessInstance()?->getId(),
            $entity->getEventPhase()
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : ['value' => $json];
    }
}
