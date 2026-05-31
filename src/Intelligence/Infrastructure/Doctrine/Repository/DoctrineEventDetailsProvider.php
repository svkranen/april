<?php

namespace App\Intelligence\Infrastructure\Doctrine\Repository;

use App\Intelligence\Application\EventContextSnapshotDetails;
use App\Intelligence\Application\EventDetails;
use App\Intelligence\Application\EventDetailsProvider;
use App\Intelligence\Infrastructure\Doctrine\Entity\ContextSnapshotEntity;
use App\Intelligence\Infrastructure\Doctrine\Entity\ProcessEventEntity;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineEventDetailsProvider implements EventDetailsProvider
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    public function find(int $eventId): ?EventDetails
    {
        $event = $this->entityManager->find(ProcessEventEntity::class, $eventId);
        if (!$event instanceof ProcessEventEntity) {
            return null;
        }

        return new EventDetails(
            $event->getId(),
            $event->getExternalEventKey(),
            $event->getSourceSystem(),
            $event->getProcessKey(),
            $event->getEventKey(),
            $event->getStepKey(),
            $event->getDocumentExternalId(),
            $event->getDocumentUuid(),
            $event->getDocumentVersion(),
            $event->getActorRef(),
            $event->getProcessInstance()?->getId(),
            $event->getOccurredAt(),
            $event->getReceivedAt(),
            $event->getRawEventJson(),
            $event->getNormalizedEventJson(),
            $this->contextSnapshots($event->getExternalEventKey())
        );
    }

    /**
     * @return array<int, EventContextSnapshotDetails>
     */
    private function contextSnapshots(string $externalEventKey): array
    {
        /** @var array<int, ContextSnapshotEntity> $snapshots */
        $snapshots = $this->entityManager->getRepository(ContextSnapshotEntity::class)->findBy(
            ['externalEventKey' => $externalEventKey],
            ['capturedAt' => 'ASC']
        );

        return array_map(
            static fn (ContextSnapshotEntity $snapshot): EventContextSnapshotDetails => new EventContextSnapshotDetails(
                $snapshot->getId(),
                $snapshot->getCapturedAt(),
                $snapshot->getContextJson(),
                $snapshot->getWarnings(),
                $snapshot->getOccurredAt(),
                $snapshot->getLoadedAt(),
                $snapshot->getIncomingEventId(),
                $snapshot->getFreshnessSeconds(),
                $snapshot->isFreshForDecisionCheck()
            ),
            $snapshots
        );
    }
}
