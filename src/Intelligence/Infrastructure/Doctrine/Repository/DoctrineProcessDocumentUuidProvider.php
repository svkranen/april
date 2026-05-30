<?php

namespace App\Intelligence\Infrastructure\Doctrine\Repository;

use App\Intelligence\Application\ProcessDocumentUuidProvider;
use App\Intelligence\Application\ProcessDocumentRef;
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
        return array_map(
            static fn (ProcessDocumentRef $ref): string => $ref->documentUuid,
            $this->documentRefsForProcess($processKey, $since, $limit)
        );
    }

    public function documentRefsForProcess(string $processKey, ?DateTimeImmutable $since = null, ?int $limit = null): array
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('event.documentUuid AS documentUuid')
            ->addSelect('MAX(event.documentExternalId) AS documentExternalId')
            ->addSelect('MAX(event.documentVersion) AS documentVersion')
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

        /** @var array<int, array{documentUuid: string, documentExternalId: string|null, documentVersion: int|string|null}> $rows */
        $rows = $queryBuilder->getQuery()->getArrayResult();

        return array_map(
            static fn (array $row): ProcessDocumentRef => new ProcessDocumentRef(
                $row['documentUuid'],
                $row['documentExternalId'] === null || $row['documentExternalId'] === '' ? null : (string) $row['documentExternalId'],
                $row['documentVersion'] === null ? null : (int) $row['documentVersion']
            ),
            $rows
        );
    }
}
