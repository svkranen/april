<?php

namespace App\Tests\Intelligence\Infrastructure\Doctrine;

use App\Intelligence\Infrastructure\Doctrine\Repository\DoctrineDocumentListProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;

class DoctrineDocumentListProviderTest extends TestCase
{
    public function testReturnsEmptyListWhenNoEvents(): void
    {
        $provider = new DoctrineDocumentListProvider($this->entityManager([]));

        self::assertSame([], $provider->documentsForProcess('ai-rechnungen'));
    }

    public function testMapsAggregatedRowsToDocumentListRows(): void
    {
        $provider = new DoctrineDocumentListProvider($this->entityManager([
            [
                'documentUuid' => 'uuid-1',
                'documentExternalId' => 'DOC-1',
                'documentVersion' => '2',
                'eventCount' => '3',
                'lastEventAt' => '2026-06-15 10:00:00',
            ],
            [
                'documentUuid' => 'uuid-2',
                'documentExternalId' => null,
                'documentVersion' => null,
                'eventCount' => 1,
                'lastEventAt' => null,
            ],
        ]));

        $rows = $provider->documentsForProcess('ai-rechnungen', 200);

        self::assertCount(2, $rows);

        self::assertSame('uuid-1', $rows[0]->documentUuid);
        self::assertSame('DOC-1', $rows[0]->documentExternalId);
        self::assertSame(2, $rows[0]->documentVersion);
        self::assertSame(3, $rows[0]->eventCount);
        self::assertNotNull($rows[0]->lastEventAt);
        self::assertSame('2026-06-15 10:00:00', $rows[0]->lastEventAt->format('Y-m-d H:i:s'));

        self::assertSame('uuid-2', $rows[1]->documentUuid);
        self::assertNull($rows[1]->documentExternalId);
        self::assertNull($rows[1]->documentVersion);
        self::assertSame(1, $rows[1]->eventCount);
        self::assertNull($rows[1]->lastEventAt);
    }

    /**
     * @param array<int, array<string, mixed>> $arrayResult
     */
    private function entityManager(array $arrayResult): EntityManagerInterface
    {
        $query = $this->createMock(Query::class);
        $query->method('getArrayResult')->willReturn($arrayResult);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        foreach (['select', 'addSelect', 'from', 'where', 'andWhere', 'groupBy', 'orderBy', 'setParameter', 'setMaxResults'] as $method) {
            $queryBuilder->method($method)->willReturnSelf();
        }
        $queryBuilder->method('getQuery')->willReturn($query);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('createQueryBuilder')->willReturn($queryBuilder);

        return $entityManager;
    }
}
