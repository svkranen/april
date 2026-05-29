<?php

namespace App\Intelligence\Infrastructure\Doctrine\Repository;

use App\Intelligence\Application\ProcessInstanceRepository;
use App\Intelligence\Domain\ProcessInstance;
use App\Intelligence\Infrastructure\Doctrine\Entity\ProcessInstanceEntity;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineProcessInstanceRepository implements ProcessInstanceRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    public function findByIdentity(
        string $sourceSystem,
        ?string $documentUuid,
        string $documentExternalId,
        int $documentVersion,
        string $processKey,
        string $templateVersion
    ): ?ProcessInstance {
        $entity = $this->entityManager->getRepository(ProcessInstanceEntity::class)->findOneBy([
            'sourceSystem' => $sourceSystem,
            'documentIdentityKey' => ProcessInstanceEntity::documentIdentityKey($documentUuid, $documentExternalId),
            'documentVersion' => $documentVersion,
            'processKey' => $processKey,
            'templateVersion' => $templateVersion,
        ]);

        return $entity instanceof ProcessInstanceEntity ? $this->toDomain($entity) : null;
    }

    public function save(ProcessInstance $instance): ProcessInstance
    {
        $entity = $instance->id !== null ? $this->entityManager->find(ProcessInstanceEntity::class, $instance->id) : null;
        if (!$entity instanceof ProcessInstanceEntity) {
            $entity = new ProcessInstanceEntity();
        }

        $entity->setSourceSystem($instance->sourceSystem);
        $entity->setProcessKey($instance->processKey);
        $entity->setTemplateVersion($instance->templateVersion);
        $entity->setDocumentExternalId($instance->documentExternalId);
        $entity->setDocumentUuid($instance->documentUuid);
        $entity->setDocumentIdentityKey(ProcessInstanceEntity::documentIdentityKey($instance->documentUuid, $instance->documentExternalId));
        $entity->setDocumentVersion($instance->documentVersion);
        $entity->setStatus($instance->status);
        $entity->setCurrentStepKey($instance->currentStepKey);
        $entity->setStartedAt($instance->startedAt);
        $entity->setLastEventAt($instance->lastEventAt);
        $entity->setEndedAt($instance->endedAt);
        $entity->setCreatedAt($instance->createdAt);
        $entity->setUpdatedAt($instance->updatedAt);

        $this->entityManager->persist($entity);
        $this->entityManager->flush();

        return $this->toDomain($entity);
    }

    public function count(): int
    {
        return $this->entityManager->getRepository(ProcessInstanceEntity::class)->count([]);
    }

    private function toDomain(ProcessInstanceEntity $entity): ProcessInstance
    {
        return new ProcessInstance(
            $entity->getId(),
            $entity->getSourceSystem(),
            $entity->getProcessKey(),
            $entity->getTemplateVersion(),
            $entity->getDocumentExternalId(),
            $entity->getDocumentUuid(),
            $entity->getDocumentVersion(),
            $entity->getStatus(),
            $entity->getCurrentStepKey(),
            $entity->getStartedAt(),
            $entity->getLastEventAt(),
            $entity->getEndedAt(),
            $entity->getCreatedAt(),
            $entity->getUpdatedAt(),
            []
        );
    }
}
