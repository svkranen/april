<?php

namespace App\Intelligence\Infrastructure\Doctrine\Repository;

use App\Intelligence\Application\ContextCoverageReport;
use App\Intelligence\Application\ContextCoverageReportBuilder;
use App\Intelligence\Application\ContextCoverageReportProvider;
use App\Intelligence\Domain\ContextSnapshot;
use App\Intelligence\Domain\DocumentRef;
use App\Intelligence\Infrastructure\Doctrine\Entity\ContextSnapshotEntity;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineContextCoverageReportProvider implements ContextCoverageReportProvider
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ContextCoverageReportBuilder $builder = new ContextCoverageReportBuilder()
    ) {
    }

    public function build(string $processKey): ContextCoverageReport
    {
        /** @var array<int, ContextSnapshotEntity> $entities */
        $entities = $this->entityManager->getRepository(ContextSnapshotEntity::class)->findBy(
            ['processKey' => $processKey],
            ['capturedAt' => 'ASC']
        );

        return $this->builder->build(
            $processKey,
            array_map(
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
                    $entity->getProcessInstance()?->getId()
                ),
                $entities
            )
        );
    }
}
