<?php

namespace App\Intelligence\Application;

/**
 * Lists the process keys observed in a document's timeline and whether a
 * process template is known for each of them.
 */
final class DocumentObservedProcessKeysProvider
{
    public function __construct(
        private readonly DocumentTimelineProvider $timelineProvider,
        private readonly ProcessTemplateProvider $templateProvider
    ) {
    }

    /**
     * @return array<string, bool> observed process key -> template known
     */
    public function knownTemplatesByProcessKey(string $documentUuid, ?int $documentVersion = null): array
    {
        $timeline = $this->timelineProvider->build($documentUuid);

        $processKeys = [];
        foreach ($timeline->events as $event) {
            if ($documentVersion === null || $event->documentVersion === $documentVersion) {
                $processKeys[$event->processKey] = true;
            }
        }
        foreach ($timeline->instances as $instance) {
            if ($documentVersion === null || $instance->documentVersion === $documentVersion) {
                $processKeys[$instance->processKey] = true;
            }
        }

        $known = [];
        foreach (array_keys($processKeys) as $processKey) {
            $known[$processKey] = $this->templateProvider->findByProcessKey($processKey) !== null;
        }

        return $known;
    }
}
