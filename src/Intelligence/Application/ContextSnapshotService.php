<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ContextSnapshot;
use App\Intelligence\Domain\DocumentRef;
use App\Intelligence\Domain\ProcessEventRecord;
use App\Intelligence\Port\ContextProvider;
use DateTimeImmutable;

final class ContextSnapshotService
{
    public function __construct(
        private readonly ContextProfileProvider $profileProvider,
        private readonly ContextProvider $contextProvider,
        private readonly ContextSnapshotStore $snapshotStore,
        private readonly ?TemplateContextProviderResolver $templateContextProviderResolver = null
    ) {
    }

    public function captureForEvent(ProcessEventRecord $event): ContextSnapshotResult
    {
        $profile = $this->profileProvider->profileForProcess($event->processKey);
        $document = new DocumentRef(
            $event->sourceSystem,
            $event->documentExternalId,
            $event->documentUuid,
            $event->documentVersion
        );

        $contextProvider = $this->contextProvider;
        $requiredFields = $profile->requiredFields;
        $templateContext = $this->templateContextProviderResolver?->resolve($event->processKey);
        if ($templateContext !== null) {
            $contextProvider = $templateContext->contextProvider;
            $requiredFields = $templateContext->requiredFields;
        }

        $attributes = $contextProvider->loadAttributes($document, $requiredFields);
        $warnings = $this->missingFieldWarnings($requiredFields, $attributes);
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
