<?php

namespace App\Intelligence\Infrastructure\Doctrine\Repository;

use App\Intelligence\Application\ProcessResetResult;
use App\Intelligence\Application\ProcessResetter;
use App\Intelligence\Infrastructure\Doctrine\Entity\ContextSnapshotEntity;
use App\Intelligence\Infrastructure\Doctrine\Entity\ProcessEventEntity;
use App\Intelligence\Infrastructure\Doctrine\Entity\ProcessInstanceEntity;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineProcessResetter implements ProcessResetter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    public function reset(string $processKey, ?string $documentUuid = null, bool $dryRun = false): ProcessResetResult
    {
        $criteria = ['processKey' => $processKey];
        if ($documentUuid !== null) {
            $criteria['documentUuid'] = $documentUuid;
        }

        $events = $this->entityManager->getRepository(ProcessEventEntity::class)->findBy($criteria);
        $instances = $this->entityManager->getRepository(ProcessInstanceEntity::class)->findBy($criteria);
        $snapshots = $this->entityManager->getRepository(ContextSnapshotEntity::class)->findBy($criteria);

        if (!$dryRun) {
            foreach ([$snapshots, $events, $instances] as $entities) {
                foreach ($entities as $entity) {
                    $this->entityManager->remove($entity);
                }
            }

            $this->entityManager->flush();
        }

        return new ProcessResetResult(
            count($events),
            count($instances),
            count($snapshots),
            0,
            0,
            $dryRun
        );
    }
}
