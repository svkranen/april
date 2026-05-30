<?php

namespace App\Intelligence\Infrastructure\Doctrine\Repository;

use App\Intelligence\Application\ProcessDocumentUuidProvider;
use App\Intelligence\Infrastructure\Doctrine\Entity\ProcessEventEntity;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineProcessDocumentUuidProvider implements ProcessDocumentUuidProvider
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    public function documentUuidsForProcess(string $processKey, ?DateTimeImmutable $since = null, ?int $limit = null): array
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('event.documentUuid AS documentUuid')
            ->addSelect('MAX(event.receivedAt) AS latestReceivedAt')
            ->from(ProcessEventEntity::class, 'event')
            ->andWhere('event.processKey = :processKey')
            ->andWhere('event.documentUuid IS NOT NULL')
            ->groupBy('event.documentUuid')
            ->orderBy('latestReceivedAt', 'DESC')
            ->setParameter('processKey', $processKey);

        if ($since !== null) {
            $queryBuilder
                ->andWhere('event.receivedAt >= :since OR event.occurredAt >= :since')
                ->setParameter('since', $since);
        }

        if ($limit !== null) {
            $queryBuilder->setMaxResults($limit);
        }

        /** @var array<int, array{documentUuid: string}> $rows */
        $rows = $queryBuilder->getQuery()->getArrayResult();

        return array_map(
            static fn (array $row): string => $row['documentUuid'],
            $rows
        );
    }
}
