<?php

namespace App\Intelligence\Infrastructure\Doctrine\Repository;

use App\Intelligence\Application\DocumentListProvider;
use App\Intelligence\Application\DocumentListRow;
use App\Intelligence\Infrastructure\Doctrine\Entity\ProcessEventEntity;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds the per-template document list from stored process events, grouped by
 * documentUuid with cheap aggregates (event count, latest event timestamp).
 */
final class DoctrineDocumentListProvider implements DocumentListProvider
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    public function documentsForProcess(string $processKey, ?int $limit = null): array
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('event.documentUuid AS documentUuid')
            ->addSelect('MAX(event.documentExternalId) AS documentExternalId')
            ->addSelect('MAX(event.documentVersion) AS documentVersion')
            ->addSelect('COUNT(event.id) AS eventCount')
            ->addSelect('MAX(event.receivedAt) AS lastEventAt')
            ->from(ProcessEventEntity::class, 'event')
            ->where('event.processKey = :processKey')
            ->andWhere('event.documentUuid IS NOT NULL')
            ->groupBy('event.documentUuid')
            ->orderBy('lastEventAt', 'DESC')
            ->setParameter('processKey', $processKey);

        if ($limit !== null) {
            $queryBuilder->setMaxResults($limit);
        }

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $queryBuilder->getQuery()->getArrayResult();

        return array_map(fn (array $row): DocumentListRow => $this->toRow($row), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toRow(array $row): DocumentListRow
    {
        $externalId = $row['documentExternalId'] ?? null;
        $version = $row['documentVersion'] ?? null;
        $last = $row['lastEventAt'] ?? null;

        if ($last instanceof DateTimeInterface) {
            $lastEventAt = DateTimeImmutable::createFromInterface($last);
        } elseif (is_string($last) && $last !== '') {
            $lastEventAt = new DateTimeImmutable($last);
        } else {
            $lastEventAt = null;
        }

        return new DocumentListRow(
            (string) $row['documentUuid'],
            $externalId === null || $externalId === '' ? null : (string) $externalId,
            $version === null ? null : (int) $version,
            isset($row['eventCount']) ? (int) $row['eventCount'] : 0,
            $lastEventAt
        );
    }
}
