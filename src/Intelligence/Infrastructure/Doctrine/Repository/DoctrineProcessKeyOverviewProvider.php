<?php

namespace App\Intelligence\Infrastructure\Doctrine\Repository;

use App\Intelligence\Application\ProcessKeyDocumentOverviewRow;
use App\Intelligence\Application\ProcessKeyOverviewProvider;
use App\Intelligence\Application\ProcessKeyOverviewRow;
use App\Intelligence\Infrastructure\Doctrine\Entity\ProcessEventEntity;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineProcessKeyOverviewProvider implements ProcessKeyOverviewProvider
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    public function processKeys(): array
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('event.processKey AS processKey')
            ->addSelect('COUNT(event.id) AS eventCount')
            ->addSelect('COUNT(DISTINCT event.documentUuid) AS documentCount')
            ->from(ProcessEventEntity::class, 'event')
            ->andWhere('event.documentUuid IS NOT NULL')
            ->groupBy('event.processKey')
            ->orderBy('event.processKey', 'ASC');

        /** @var array<int, array{processKey: string, eventCount: int|string, documentCount: int|string}> $rows */
        $rows = $queryBuilder->getQuery()->getArrayResult();

        return array_map(
            static fn (array $row): ProcessKeyOverviewRow => new ProcessKeyOverviewRow(
                (string) $row['processKey'],
                (int) $row['documentCount'],
                (int) $row['eventCount']
            ),
            $rows
        );
    }

    public function documentsForProcessKey(string $processKey, ?int $limit = null): array
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('event.documentUuid AS documentUuid')
            ->addSelect('MAX(event.documentExternalId) AS documentExternalId')
            ->addSelect('event.documentVersion AS documentVersion')
            ->addSelect('COUNT(event.id) AS eventCount')
            ->addSelect('MIN(event.occurredAt) AS firstOccurredAt')
            ->addSelect('MAX(event.occurredAt) AS lastOccurredAt')
            ->from(ProcessEventEntity::class, 'event')
            ->where('event.processKey = :processKey')
            ->andWhere('event.documentUuid IS NOT NULL')
            ->groupBy('event.documentUuid')
            ->addGroupBy('event.documentVersion')
            ->orderBy('lastOccurredAt', 'DESC')
            ->setParameter('processKey', $processKey);

        if ($limit !== null) {
            $queryBuilder->setMaxResults($limit);
        }

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $queryBuilder->getQuery()->getArrayResult();

        return array_map(fn (array $row): ProcessKeyDocumentOverviewRow => $this->toDocumentRow($row), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toDocumentRow(array $row): ProcessKeyDocumentOverviewRow
    {
        $externalId = $row['documentExternalId'] ?? null;
        $version = $row['documentVersion'] ?? null;

        return new ProcessKeyDocumentOverviewRow(
            (string) $row['documentUuid'],
            $externalId === null || $externalId === '' ? null : (string) $externalId,
            $version === null ? null : (int) $version,
            isset($row['eventCount']) ? (int) $row['eventCount'] : 0,
            $this->dateTime($row['firstOccurredAt'] ?? null),
            $this->dateTime($row['lastOccurredAt'] ?? null)
        );
    }

    private function dateTime(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        if (is_string($value) && $value !== '') {
            return new DateTimeImmutable($value);
        }

        return null;
    }
}
