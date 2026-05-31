<?php

namespace App\Intelligence\Infrastructure\Doctrine\Repository;

use App\Intelligence\Application\IncomingEventStore;
use App\Intelligence\Domain\DateTimeNormalizer;
use App\Intelligence\Domain\IncomingEvent;
use App\Intelligence\Infrastructure\Doctrine\Entity\IncomingEventEntity;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineIncomingEventRepository implements IncomingEventStore
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DateTimeNormalizer $dateTimeNormalizer = new DateTimeNormalizer()
    )
    {
    }

    public function save(IncomingEvent $event): IncomingEvent
    {
        $entity = new IncomingEventEntity();
        $this->apply($entity, $event);
        $now = $this->dateTimeNormalizer->nowUtc();
        $entity->setCreatedAt($now);
        $entity->setUpdatedAt($now);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();

        return $this->toDomain($entity);
    }

    public function pending(int $limit, int $maxRetries): array
    {
        /** @var array<int, IncomingEventEntity> $entities */
        $entities = $this->entityManager->createQueryBuilder()
            ->select('event')
            ->from(IncomingEventEntity::class, 'event')
            ->where('event.status = :pending OR (event.status = :failed AND event.retryCount < :maxRetries)')
            ->setParameter('pending', IncomingEvent::STATUS_PENDING)
            ->setParameter('failed', IncomingEvent::STATUS_FAILED)
            ->setParameter('maxRetries', $maxRetries)
            ->orderBy('event.receivedAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_map(fn (IncomingEventEntity $entity): IncomingEvent => $this->toDomain($entity), $entities);
    }

    public function markProcessing(IncomingEvent $event): IncomingEvent
    {
        return $this->mark($event, IncomingEvent::STATUS_PROCESSING, $event->retryCount, null, null);
    }

    public function markProcessed(IncomingEvent $event): IncomingEvent
    {
        return $this->mark($event, IncomingEvent::STATUS_PROCESSED, $event->retryCount, null, $this->dateTimeNormalizer->nowUtc());
    }

    public function markFailed(IncomingEvent $event, string $error, int $maxRetries): IncomingEvent
    {
        $retryCount = $event->retryCount + 1;
        $status = $retryCount >= $maxRetries ? IncomingEvent::STATUS_DEAD : IncomingEvent::STATUS_FAILED;

        return $this->mark($event, $status, $retryCount, $error, null);
    }

    public function count(): int
    {
        return $this->entityManager->getRepository(IncomingEventEntity::class)->count([]);
    }

    private function mark(IncomingEvent $event, string $status, int $retryCount, ?string $error, ?DateTimeImmutable $processedAt): IncomingEvent
    {
        $entity = $this->entityManager->find(IncomingEventEntity::class, $event->id);
        if (!$entity instanceof IncomingEventEntity) {
            throw new \RuntimeException(sprintf('IncomingEvent %s not found.', (string) $event->id));
        }

        $entity->setStatus($status);
        $entity->setRetryCount($retryCount);
        $entity->setLastError($error);
        $entity->setProcessedAt($processedAt);
        $entity->setUpdatedAt($this->dateTimeNormalizer->nowUtc());
        $this->entityManager->flush();

        return $this->toDomain($entity);
    }

    private function apply(IncomingEventEntity $entity, IncomingEvent $event): void
    {
        $entity->setProcessKey($event->processKey);
        $entity->setConnectorType($event->connectorType);
        $entity->setConnectionName($event->connectionName);
        $entity->setDocumentId($event->documentId);
        $entity->setDocumentUuid($event->documentUuid);
        $entity->setEventKey($event->eventKey);
        $entity->setExternalEventKey($event->externalEventKey);
        $entity->setOccurredAt($event->occurredAt);
        $entity->setReceivedAt($event->receivedAt);
        $entity->setContentType($event->contentType);
        $entity->setRawPayload($event->rawPayload);
        $entity->setNormalizedPayloadJson($event->normalizedPayloadJson);
        $entity->setStatus($event->status);
        $entity->setRetryCount($event->retryCount);
        $entity->setLastError($event->lastError);
        $entity->setProcessedAt($event->processedAt);
    }

    private function toDomain(IncomingEventEntity $entity): IncomingEvent
    {
        return new IncomingEvent(
            $entity->getId(),
            $entity->getProcessKey(),
            $entity->getConnectorType(),
            $entity->getConnectionName(),
            $entity->getDocumentId(),
            $entity->getDocumentUuid(),
            $entity->getEventKey(),
            $entity->getExternalEventKey(),
            $entity->getOccurredAt(),
            $entity->getReceivedAt(),
            $entity->getContentType(),
            $entity->getRawPayload(),
            $entity->getNormalizedPayloadJson(),
            $entity->getStatus(),
            $entity->getRetryCount(),
            $entity->getLastError(),
            $entity->getProcessedAt(),
            $entity->getCreatedAt(),
            $entity->getUpdatedAt()
        );
    }
}
