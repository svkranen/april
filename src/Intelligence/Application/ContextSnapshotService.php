<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ContextSnapshot;
use App\Intelligence\Domain\DocumentRef;
use App\Intelligence\Domain\ProcessEvent;
use App\Intelligence\Port\ContextProvider;
use DateTimeImmutable;

final class ContextSnapshotService
{
    public function __construct(
        private readonly ContextProfileProvider $profileProvider,
        private readonly ContextProvider $contextProvider,
        private readonly ContextSnapshotStore $snapshotStore
    ) {
    }

    public function captureForEvent(ProcessEvent $event): ContextSnapshotResult
    {
        $profile = $this->profileProvider->profileForProcess($event->processKey);
        $document = new DocumentRef(
            $event->sourceSystem,
            $event->documentExternalId,
            $event->documentUuid,
            $event->documentVersion
        );

        $attributes = $this->contextProvider->loadAttributes($document, $profile->requiredFields);
        $warnings = $this->missingFieldWarnings($profile->requiredFields, $attributes);
        $snapshot = new ContextSnapshot(
            $document,
            new DateTimeImmutable(),
            $attributes,
            $warnings,
            $event->processKey,
            $event->externalEventKey,
            $event->processInstanceId
        );

        return new ContextSnapshotResult($this->snapshotStore->save($snapshot), $warnings);
    }

    /**
     * @param array<int, string> $requiredFields
     * @param array<string, mixed> $attributes
     * @return array<int, string>
     */
    private function missingFieldWarnings(array $requiredFields, array $attributes): array
    {
        $warnings = [];
        foreach ($requiredFields as $field) {
            if (!array_key_exists($field, $attributes) || $attributes[$field] === null || $attributes[$field] === '') {
                $warnings[] = sprintf('Missing required context field "%s".', $field);
            }
        }

        return $warnings;
    }
}
