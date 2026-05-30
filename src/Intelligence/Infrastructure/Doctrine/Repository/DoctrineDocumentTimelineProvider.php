<?php

namespace App\Intelligence\Infrastructure\Doctrine\Repository;

use App\Intelligence\Application\DocumentTimelineEventRow;
use App\Intelligence\Application\DocumentTimelineInstanceRow;
use App\Intelligence\Application\DocumentTimelineProvider;
use App\Intelligence\Application\DocumentTimelineReport;
use App\Intelligence\Application\EventTimelineOrder;
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

    public function build(string $documentUuid, EventTimelineOrder $order = EventTimelineOrder::DEFAULT): DocumentTimelineReport
    {
        /** @var array<int, ProcessInstanceEntity> $instances */
        $instances = $this->entityManager->getRepository(ProcessInstanceEntity::class)->findBy(
            ['documentUuid' => $documentUuid],
            ['documentVersion' => 'ASC', 'id' => 'ASC']
        );

        /** @var array<int, ProcessEventEntity> $events */
        $events = $this->entityManager->getRepository(ProcessEventEntity::class)->findBy(
            ['documentUuid' => $documentUuid],
            $this->orderBy($order)
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
                    $entity->getReceivedAt(),
                    $entity->getId(),
                    $entity->getProcessInstance()?->getId(),
                    $snapshotsByEventKey[$entity->getExternalEventKey()] ?? null,
                    $entity->getEventPhase()
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
                'attributes' => $snapshot->getContextJson(),
                'fields' => array_keys($snapshot->getContextJson()),
                'warnings' => $snapshot->getWarnings(),
            ];
        }

        return $summaries;
    }

    /**
     * @return array<string, string>
     */
    private function orderBy(EventTimelineOrder $order): array
    {
        return match ($order) {
            EventTimelineOrder::OccurredAt => ['occurredAt' => 'ASC', 'id' => 'ASC'],
            EventTimelineOrder::ReceivedAt => ['receivedAt' => 'ASC', 'id' => 'ASC'],
            EventTimelineOrder::OccurredThenReceived => ['occurredAt' => 'ASC', 'receivedAt' => 'ASC', 'id' => 'ASC'],
        };
    }
}
