<?php

namespace App\Intelligence\Infrastructure\Doctrine\Repository;

use App\Intelligence\Application\VisibilityCheckEvaluationResult;
use App\Intelligence\Application\VisibilityCheckResultProvider;
use App\Intelligence\Application\VisibilityCheckResultRecord;
use App\Intelligence\Application\VisibilityCheckResultSaveContext;
use App\Intelligence\Application\VisibilityCheckResultStore;
use App\Intelligence\Infrastructure\Doctrine\Entity\VisibilityCheckResultEntity;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineVisibilityCheckResultRepository implements VisibilityCheckResultStore, VisibilityCheckResultProvider
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
    }

    public function save(VisibilityCheckEvaluationResult $result, ?VisibilityCheckResultSaveContext $context = null): void
    {
        $this->persist($result, $context ?? new VisibilityCheckResultSaveContext());
        $this->entityManager->flush();
    }

    public function saveMany(array $results, ?VisibilityCheckResultSaveContext $context = null): int
    {
        $context ??= new VisibilityCheckResultSaveContext();
        foreach ($results as $result) {
            $this->persist($result, $context);
        }

        $this->entityManager->flush();

        return count($results);
    }

    public function findByDocument(string $documentUuid, ?string $processKey = null): array
    {
        $criteria = ['documentUuid' => $documentUuid];
        if ($processKey !== null) {
            $criteria['processKey'] = $processKey;
        }

        /** @var array<int, VisibilityCheckResultEntity> $entities */
        $entities = $this->entityManager->getRepository(VisibilityCheckResultEntity::class)->findBy(
            $criteria,
            ['checkedAt' => 'ASC', 'id' => 'ASC']
        );

        return array_map(fn (VisibilityCheckResultEntity $entity): VisibilityCheckResultRecord => $this->toRecord($entity), $entities);
    }

    private function persist(VisibilityCheckEvaluationResult $result, VisibilityCheckResultSaveContext $context): void
    {
        $now = new DateTimeImmutable();
        $entity = new VisibilityCheckResultEntity();
        $entity->setProcessEventId($context->processEventId);
        $entity->setProcessInstanceId($context->processInstanceId);
        $entity->setExternalEventKey($context->externalEventKey);
        $entity->setDocumentUuid($result->documentUuid);
        $entity->setDocumentVersion($context->documentVersion);
        $entity->setProcessKey($result->processKey);
        $entity->setSourceSystem($context->sourceSystem ?? 'unknown');
        $entity->setStepKey($result->stepKey);
        $entity->setEventPhase($result->eventPhase);
        $entity->setCheckKey($result->checkKey);
        $entity->setProfileKey($result->profileKey === '' ? null : $result->profileKey);
        $entity->setProbeKey($result->probeKey);
        $entity->setProbeType($context->probeType);
        $entity->setProbeRef($context->probeRef);
        $entity->setExpected($result->expected);
        $entity->setActual($result->actual);
        $entity->setStatus($result->status);
        $entity->setReason($result->reason);
        $entity->setCheckedAt($now);
        $entity->setAttemptNo($context->attemptNo ?? 1);
        $entity->setIsFinal($context->isFinal);
        $entity->setDocumentCount($this->documentCount($result));
        $entity->setRawResultJson($context->rawResult);
        $entity->setDetailsJson($context->details ?? $result->details);
        $entity->setCreatedAt($now);

        $this->entityManager->persist($entity);
    }

    private function documentCount(VisibilityCheckEvaluationResult $result): ?int
    {
        $documentCount = $result->details['documentCount'] ?? null;

        return is_int($documentCount) ? $documentCount : null;
    }

    private function toRecord(VisibilityCheckResultEntity $entity): VisibilityCheckResultRecord
    {
        return new VisibilityCheckResultRecord(
            $entity->getId() ?? 0,
            $entity->getDocumentUuid(),
            $entity->getDocumentVersion(),
            $entity->getProcessKey(),
            $entity->getSourceSystem(),
            $entity->getStepKey(),
            $entity->getEventPhase(),
            $entity->getCheckKey(),
            $entity->getProfileKey(),
            $entity->getProbeKey(),
            $entity->getProbeType(),
            $entity->getProbeRef(),
            $entity->getExpected(),
            $entity->getActual(),
            $entity->getStatus(),
            $entity->getReason(),
            $entity->getCheckedAt(),
            $entity->getAttemptNo(),
            $entity->isFinal(),
            $entity->getDocumentCount(),
            $entity->getDetailsJson()
        );
    }
}
