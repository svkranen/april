<?php

namespace App\Intelligence\Infrastructure\Doctrine\Repository;

use App\Intelligence\Application\EventListFilter;
use App\Intelligence\Application\EventListProvider;
use App\Intelligence\Application\EventListRow;
use App\Intelligence\Infrastructure\Doctrine\Entity\ProcessEventEntity;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineEventListProvider implements EventListProvider
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    public function list(EventListFilter $filter): array
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('event')
            ->from(ProcessEventEntity::class, 'event')
            ->orderBy('event.receivedAt', 'DESC')
            ->addOrderBy('event.id', 'DESC')
            ->setMaxResults($filter->limit);

        if ($filter->processKey !== null) {
            $queryBuilder
                ->andWhere('event.processKey = :processKey')
                ->setParameter('processKey', $filter->processKey);
        }

        if ($filter->documentUuid !== null) {
            $queryBuilder
                ->andWhere('event.documentUuid = :documentUuid')
                ->setParameter('documentUuid', $filter->documentUuid);
        }

        if ($filter->documentExternalId !== null) {
            $queryBuilder
                ->andWhere('event.documentExternalId = :documentExternalId')
                ->setParameter('documentExternalId', $filter->documentExternalId);
        }

        if ($filter->since !== null) {
            $queryBuilder
                ->andWhere('event.receivedAt >= :since OR event.occurredAt >= :since')
                ->setParameter('since', $filter->since);
        }

        /** @var array<int, ProcessEventEntity> $entities */
        $entities = $queryBuilder->getQuery()->getResult();

        return array_map(
            static fn (ProcessEventEntity $entity): EventListRow => new EventListRow(
                $entity->getId(),
                $entity->getExternalEventKey(),
                $entity->getProcessKey(),
                $entity->getEventKey(),
                $entity->getStepKey(),
                $entity->getDocumentExternalId(),
                $entity->getDocumentUuid(),
                $entity->getDocumentVersion(),
                $entity->getProcessInstance()?->getId(),
                $entity->getOccurredAt(),
                $entity->getReceivedAt()
            ),
            $entities
        );
    }
}
