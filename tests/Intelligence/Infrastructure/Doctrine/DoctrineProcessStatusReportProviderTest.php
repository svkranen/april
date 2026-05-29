<?php

namespace App\Tests\Intelligence\Infrastructure\Doctrine;

use App\Intelligence\Infrastructure\Doctrine\Entity\ProcessEventEntity;
use App\Intelligence\Infrastructure\Doctrine\Entity\ProcessInstanceEntity;
use App\Intelligence\Infrastructure\Doctrine\Repository\DoctrineProcessStatusReportProvider;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;

class DoctrineProcessStatusReportProviderTest extends TestCase
{
    public function testBuildUsesNonReservedProcessInstanceAlias(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $instanceRepository = $this->createMock(EntityRepository::class);
        $eventRepository = $this->createMock(EntityRepository::class);
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(Query::class);

        $entityManager
            ->method('getRepository')
            ->willReturnMap([
                [ProcessInstanceEntity::class, $instanceRepository],
                [ProcessEventEntity::class, $eventRepository],
            ]);

        $entityManager
            ->expects(self::once())
            ->method('createQueryBuilder')
            ->willReturn($queryBuilder);

        $instanceRepository
            ->expects(self::once())
            ->method('count')
            ->with(['processKey' => 'invoice-process'])
            ->willReturn(1);
        $instanceRepository
            ->expects(self::once())
            ->method('findBy')
            ->willReturn([$this->processInstanceEntity()]);
        $eventRepository
            ->expects(self::once())
            ->method('findBy')
            ->willReturn([$this->processEventEntity()]);

        $queryBuilder
            ->expects(self::once())
            ->method('select')
            ->with('pi.currentStepKey AS stepKey, COUNT(pi.id) AS instanceCount')
            ->willReturnSelf();
        $queryBuilder
            ->expects(self::once())
            ->method('from')
            ->with(ProcessInstanceEntity::class, 'pi')
            ->willReturnSelf();
        $queryBuilder
            ->expects(self::once())
            ->method('where')
            ->with('pi.processKey = :processKey')
            ->willReturnSelf();
        $queryBuilder
            ->expects(self::once())
            ->method('groupBy')
            ->with('pi.currentStepKey')
            ->willReturnSelf();
        $queryBuilder
            ->expects(self::once())
            ->method('orderBy')
            ->with('pi.currentStepKey', 'ASC')
            ->willReturnSelf();
        $queryBuilder
            ->expects(self::once())
            ->method('setParameter')
            ->with('processKey', 'invoice-process')
            ->willReturnSelf();
        $queryBuilder
            ->expects(self::once())
            ->method('getQuery')
            ->willReturn($query);

        $query
            ->expects(self::once())
            ->method('getArrayResult')
            ->willReturn([
                ['stepKey' => 'received', 'instanceCount' => 1],
            ]);

        $report = (new DoctrineProcessStatusReportProvider($entityManager))->build('invoice-process');

        self::assertSame(1, $report->totalInstances);
        self::assertSame(['received' => 1], $report->countsByStep);
        self::assertCount(1, $report->openInstances);
        self::assertCount(1, $report->latestEvents);
    }

    private function processInstanceEntity(): ProcessInstanceEntity
    {
        $entity = new ProcessInstanceEntity();
        $entity->setSourceSystem('amagno');
        $entity->setProcessKey('invoice-process');
        $entity->setTemplateVersion('draft');
        $entity->setDocumentExternalId('doc-1');
        $entity->setDocumentUuid('uuid-1');
        $entity->setDocumentVersion(1);
        $entity->setStatus('running');
        $entity->setCurrentStepKey('received');
        $entity->setStartedAt(new DateTimeImmutable('2026-05-29T10:00:00+00:00'));
        $entity->setLastEventAt(new DateTimeImmutable('2026-05-29T10:00:00+00:00'));
        $entity->setEndedAt(null);
        $entity->setCreatedAt(new DateTimeImmutable('2026-05-29T10:00:00+00:00'));
        $entity->setUpdatedAt(new DateTimeImmutable('2026-05-29T10:00:00+00:00'));

        return $entity;
    }

    private function processEventEntity(): ProcessEventEntity
    {
        $entity = new ProcessEventEntity();
        $entity->setExternalEventKey('evt-1');
        $entity->setSourceSystem('amagno');
        $entity->setProcessKey('invoice-process');
        $entity->setEventKey('received');
        $entity->setStepKey('received');
        $entity->setDocumentExternalId('doc-1');
        $entity->setDocumentUuid('uuid-1');
        $entity->setDocumentVersion(1);
        $entity->setActorRef('user-1');
        $entity->setOccurredAt(new DateTimeImmutable('2026-05-29T10:00:00+00:00'));
        $entity->setReceivedAt(new DateTimeImmutable('2026-05-29T10:00:01+00:00'));
        $entity->setRawEventJson([]);
        $entity->setNormalizedEventJson([]);

        return $entity;
    }
}
