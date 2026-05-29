<?php

namespace App\Intelligence\Infrastructure\Doctrine\Repository;

use App\Intelligence\Application\ProcessStatusEventRow;
use App\Intelligence\Application\ProcessStatusInstanceRow;
use App\Intelligence\Application\ProcessStatusReport;
use App\Intelligence\Application\ProcessStatusReportProvider;
use App\Intelligence\Infrastructure\Doctrine\Entity\ProcessEventEntity;
use App\Intelligence\Infrastructure\Doctrine\Entity\ProcessInstanceEntity;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineProcessStatusReportProvider implements ProcessStatusReportProvider
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    public function build(string $processKey): ProcessStatusReport
    {
        $instanceRepository = $this->entityManager->getRepository(ProcessInstanceEntity::class);
        $total = $instanceRepository->count(['processKey' => $processKey]);

        $stepRows = $this->entityManager->createQueryBuilder()
            ->select('pi.currentStepKey AS stepKey, COUNT(pi.id) AS instanceCount')
            ->from(ProcessInstanceEntity::class, 'pi')
            ->where('pi.processKey = :processKey')
            ->groupBy('pi.currentStepKey')
            ->orderBy('pi.currentStepKey', 'ASC')
            ->setParameter('processKey', $processKey)
            ->getQuery()
            ->getArrayResult();

        $countsByStep = [];
        foreach ($stepRows as $row) {
            $countsByStep[(string) $row['stepKey']] = (int) $row['instanceCount'];
        }

        /** @var array<int, ProcessInstanceEntity> $openEntities */
        $openEntities = $instanceRepository->findBy(
            ['processKey' => $processKey, 'endedAt' => null],
            ['lastEventAt' => 'DESC']
        );
        $openInstances = array_map(
            static fn (ProcessInstanceEntity $entity): ProcessStatusInstanceRow => new ProcessStatusInstanceRow(
                $entity->getId(),
                $entity->getDocumentUuid(),
                $entity->getDocumentVersion(),
                $entity->getCurrentStepKey(),
                $entity->getLastEventAt(),
                $entity->getStatus()
            ),
            $openEntities
        );

        /** @var array<int, ProcessEventEntity> $eventEntities */
        $eventEntities = $this->entityManager->getRepository(ProcessEventEntity::class)->findBy(
            ['processKey' => $processKey],
            ['occurredAt' => 'DESC'],
            10
        );
        $latestEvents = array_map(
            static fn (ProcessEventEntity $entity): ProcessStatusEventRow => new ProcessStatusEventRow(
                $entity->getExternalEventKey(),
                $entity->getDocumentUuid(),
                $entity->getDocumentVersion(),
                $entity->getStepKey(),
                $entity->getOccurredAt()
            ),
            $eventEntities
        );

        return new ProcessStatusReport($processKey, $total, $countsByStep, $openInstances, $latestEvents);
    }
}
