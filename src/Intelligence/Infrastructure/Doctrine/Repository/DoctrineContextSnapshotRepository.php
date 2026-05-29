<?php

namespace App\Intelligence\Infrastructure\Doctrine\Repository;

use App\Intelligence\Application\ContextSnapshotStore;
use App\Intelligence\Domain\ContextSnapshot;
use App\Intelligence\Domain\DocumentRef;
use App\Intelligence\Infrastructure\Doctrine\Entity\ContextSnapshotEntity;
use App\Intelligence\Infrastructure\Doctrine\Entity\ProcessInstanceEntity;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineContextSnapshotRepository implements ContextSnapshotStore
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    public function save(ContextSnapshot $snapshot): ContextSnapshot
    {
        $entity = new ContextSnapshotEntity();
        $entity->setSourceSystem($snapshot->document->sourceSystem);
        $entity->setDocumentExternalId($snapshot->document->externalId);
        $entity->setDocumentUuid($snapshot->document->externalUuid);
        $entity->setDocumentVersion($snapshot->document->version);
        $entity->setProcessKey($snapshot->processKey);
        $entity->setExternalEventKey($snapshot->externalEventKey);
        if ($snapshot->processInstanceId !== null) {
            $entity->setProcessInstance($this->entityManager->getReference(ProcessInstanceEntity::class, $snapshot->processInstanceId));
        }
        $entity->setCapturedAt($snapshot->capturedAt);
        $entity->setContextJson($snapshot->attributes);
        $entity->setWarnings($snapshot->warnings);

        $this->entityManager->persist($entity);
        $this->entityManager->flush();

        return $this->toDomain($entity);
    }

    public function count(): int
    {
        return $this->entityManager->getRepository(ContextSnapshotEntity::class)->count([]);
    }

    private function toDomain(ContextSnapshotEntity $entity): ContextSnapshot
    {
        return new ContextSnapshot(
            new DocumentRef(
                $entity->getSourceSystem(),
                $entity->getDocumentExternalId(),
                $entity->getDocumentUuid(),
                $entity->getDocumentVersion()
            ),
            $entity->getCapturedAt(),
            $entity->getContextJson(),
            $entity->getWarnings(),
            $entity->getProcessKey(),
            $entity->getExternalEventKey(),
            $entity->getProcessInstance()?->getId()
        );
    }
}
