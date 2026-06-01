<?php

namespace App\Intelligence\Infrastructure\Doctrine\Repository;

use App\Intelligence\Application\ContextSnapshotHistoryProvider;
use App\Intelligence\Domain\ContextSnapshot;
use App\Intelligence\Domain\DocumentRef;
use App\Intelligence\Infrastructure\Doctrine\Entity\ContextSnapshotEntity;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineContextSnapshotHistoryProvider implements ContextSnapshotHistoryProvider
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
    }

    public function snapshotsForDocument(string $documentUuid, string $processKey): array
    {
        /** @var array<int, ContextSnapshotEntity> $entities */
        $entities = $this->entityManager->getRepository(ContextSnapshotEntity::class)->findBy([
            'documentUuid' => $documentUuid,
            'processKey' => $processKey,
        ]);

        return array_map(
            static fn (ContextSnapshotEntity $entity): ContextSnapshot => new ContextSnapshot(
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
                $entity->getProcessInstance()?->getId(),
                $entity->getOccurredAt(),
                $entity->getLoadedAt(),
                $entity->getIncomingEventId(),
                $entity->getFreshnessSeconds(),
                $entity->isFreshForDecisionCheck()
            ),
            $entities
        );
    }
}
