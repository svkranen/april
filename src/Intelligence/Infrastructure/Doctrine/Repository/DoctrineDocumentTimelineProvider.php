<?php

namespace App\Intelligence\Infrastructure\Doctrine\Repository;

use App\Intelligence\Application\DocumentTimelineEventRow;
use App\Intelligence\Application\DocumentTimelineInstanceRow;
use App\Intelligence\Application\DocumentTimelineProvider;
use App\Intelligence\Application\DocumentTimelineReport;
use App\Intelligence\Infrastructure\Doctrine\Entity\ContextSnapshotEntity;
use App\Intelligence\Infrastructure\Doctrine\Entity\ProcessEventEntity;
use App\Intelligence\Infrastructure\Doctrine\Entity\ProcessInstanceEntity;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineDocumentTimelineProvider implements DocumentTimelineProvider
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    public function build(string $documentUuid): DocumentTimelineReport
    {
        /** @var array<int, ProcessInstanceEntity> $instances */
        $instances = $this->entityManager->getRepository(ProcessInstanceEntity::class)->findBy(
            ['documentUuid' => $documentUuid],
            ['documentVersion' => 'ASC', 'id' => 'ASC']
        );

        /** @var array<int, ProcessEventEntity> $events */
        $events = $this->entityManager->getRepository(ProcessEventEntity::class)->findBy(
            ['documentUuid' => $documentUuid],
            ['occurredAt' => 'ASC', 'documentVersion' => 'ASC', 'id' => 'ASC']
        );

        /** @var array<int, ContextSnapshotEntity> $snapshots */
        $snapshots = $this->entityManager->getRepository(ContextSnapshotEntity::class)->findBy(
            ['documentUuid' => $documentUuid],
            ['capturedAt' => 'ASC']
        );
        $snapshotsByEventKey = $this->snapshotsByEventKey($snapshots);

        return new DocumentTimelineReport(
            $documentUuid,
            array_map(
                static fn (ProcessInstanceEntity $entity): DocumentTimelineInstanceRow => new DocumentTimelineInstanceRow(
                    $entity->getId(),
                    $entity->getProcessKey(),
                    $entity->getDocumentVersion(),
                    $entity->getCurrentStepKey(),
                    $entity->getStatus()
                ),
                $instances
            ),
            array_map(
                static fn (ProcessEventEntity $entity): DocumentTimelineEventRow => new DocumentTimelineEventRow(
                    $entity->getExternalEventKey(),
                    $entity->getEventKey(),
                    $entity->getStepKey(),
                    $entity->getProcessKey(),
                    $entity->getDocumentVersion(),
                    $entity->getOccurredAt(),
                    $entity->getProcessInstance()?->getId(),
                    $snapshotsByEventKey[$entity->getExternalEventKey()] ?? null
                ),
                $events
            )
        );
    }

    /**
     * @param array<int, ContextSnapshotEntity> $snapshots
     * @return array<string, array<string, mixed>>
     */
    private function snapshotsByEventKey(array $snapshots): array
    {
        $summaries = [];
        foreach ($snapshots as $snapshot) {
            $eventKey = $snapshot->getExternalEventKey();
            if ($eventKey === null) {
                continue;
            }

            $summaries[$eventKey] = [
                'fields' => array_keys($snapshot->getContextJson()),
                'warnings' => $snapshot->getWarnings(),
            ];
        }

        return $summaries;
    }
}
