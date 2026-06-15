<?php

namespace App\Tests\Intelligence\Infrastructure\Doctrine;

use App\Intelligence\Application\VisibilityCheckEvaluationResult;
use App\Intelligence\Application\VisibilityCheckResultSaveContext;
use App\Intelligence\Infrastructure\Doctrine\Entity\VisibilityCheckResultEntity;
use App\Intelligence\Infrastructure\Doctrine\Repository\DoctrineVisibilityCheckResultRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

class DoctrineVisibilityCheckResultRepositoryTest extends TestCase
{
    public function testSaveManyMapsEvaluationResultsToEntitiesAndFlushesOnce(): void
    {
        $persisted = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            });
        $entityManager
            ->expects(self::once())
            ->method('flush');

        $repository = new DoctrineVisibilityCheckResultRepository($entityManager);

        $count = $repository->saveMany(
            [
                $this->result('approval_location_a_today', 'visible', 'ok', null),
                $this->result('external_today', 'hidden', 'ok', null),
            ],
            new VisibilityCheckResultSaveContext(
                externalEventKey: 'evt-1',
                documentVersion: 7,
                sourceSystem: 'amagno',
                probeType: 'amagno_magnet_documents'
            )
        );

        self::assertSame(2, $count);
        self::assertCount(2, $persisted);

        $entity = $persisted[0];
        self::assertInstanceOf(VisibilityCheckResultEntity::class, $entity);
        self::assertSame('doc-1', $entity->getDocumentUuid());
        self::assertSame(7, $entity->getDocumentVersion());
        self::assertSame('invoice', $entity->getProcessKey());
        self::assertSame('amagno', $entity->getSourceSystem());
        self::assertSame('01 Rechnungen pruefen', $entity->getStepKey());
        self::assertSame('after', $entity->getEventPhase());
        self::assertSame('route_to_location_approval', $entity->getCheckKey());
        self::assertSame('approval_location_a', $entity->getProfileKey());
        self::assertSame('approval_location_a_today', $entity->getProbeKey());
        self::assertSame('amagno_magnet_documents', $entity->getProbeType());
        self::assertSame('visible', $entity->getExpected());
        self::assertSame('ok', $entity->getStatus());
        self::assertSame('evt-1', $entity->getExternalEventKey());
        self::assertSame(1, $entity->getAttemptNo());
        self::assertTrue($entity->isFinal());
        self::assertSame(3, $entity->getDocumentCount());
    }

    public function testFindByDocumentMapsEntitiesToRecords(): void
    {
        $entity = new VisibilityCheckResultEntity();
        $entity->setDocumentUuid('doc-1');
        $entity->setDocumentVersion(2);
        $entity->setProcessKey('invoice');
        $entity->setSourceSystem('amagno');
        $entity->setStepKey('received');
        $entity->setEventPhase('after');
        $entity->setCheckKey('route_to_location_approval');
        $entity->setProfileKey('approval_location_a');
        $entity->setProbeKey('approval_location_a_today');
        $entity->setProbeType('amagno_magnet_documents');
        $entity->setExpected('visible');
        $entity->setActual('visible');
        $entity->setStatus('ok');
        $entity->setReason(null);
        $entity->setCheckedAt(new DateTimeImmutable('2026-06-15T10:00:00+00:00'));
        $entity->setCreatedAt(new DateTimeImmutable('2026-06-15T10:00:00+00:00'));
        $entity->setDocumentCount(1);
        $entity->setDetailsJson(['documentCount' => 1]);

        $entityRepository = $this->createMock(EntityRepository::class);
        $entityRepository
            ->expects(self::once())
            ->method('findBy')
            ->with(
                ['documentUuid' => 'doc-1', 'processKey' => 'invoice'],
                ['checkedAt' => 'ASC', 'id' => 'ASC']
            )
            ->willReturn([$entity]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->method('getRepository')
            ->with(VisibilityCheckResultEntity::class)
            ->willReturn($entityRepository);

        $records = (new DoctrineVisibilityCheckResultRepository($entityManager))
            ->findByDocument('doc-1', 'invoice');

        self::assertCount(1, $records);
        self::assertSame('doc-1', $records[0]->documentUuid);
        self::assertSame('invoice', $records[0]->processKey);
        self::assertSame('approval_location_a_today', $records[0]->probeKey);
        self::assertSame('ok', $records[0]->status);
    }

    private function result(string $probeKey, string $expected, string $status, ?string $reason): VisibilityCheckEvaluationResult
    {
        return new VisibilityCheckEvaluationResult(
            'doc-1',
            'invoice',
            '01 Rechnungen pruefen',
            'after',
            'route_to_location_approval',
            'approval_location_a',
            $probeKey,
            $expected,
            'visible',
            $status,
            $reason,
            ['documentCount' => 3]
        );
    }
}
