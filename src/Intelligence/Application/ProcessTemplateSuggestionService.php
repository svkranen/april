<?php

namespace App\Intelligence\Application;

final class ProcessTemplateSuggestionService
{
    public function __construct(
        private readonly DocumentTimelineProvider $timelineProvider
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function suggest(string $documentUuid, string $processKey, ?int $documentVersion = null): ?array
    {
        $events = array_values(array_filter(
            $this->timelineProvider->build($documentUuid)->events,
            static fn (DocumentTimelineEventRow $event): bool => $event->processKey === $processKey
        ));

        if ($events === []) {
            return null;
        }

        $selectedVersion = $documentVersion ?? max(array_map(
            static fn (DocumentTimelineEventRow $event): int => $event->documentVersion,
            $events
        ));

        $events = array_values(array_filter(
            $events,
            static fn (DocumentTimelineEventRow $event): bool => $event->documentVersion === $selectedVersion
        ));

        if ($events === []) {
            return null;
        }

        usort(
            $events,
            static fn (DocumentTimelineEventRow $left, DocumentTimelineEventRow $right): int => [
                $left->occurredAt,
                $left->externalEventKey,
            ] <=> [
                $right->occurredAt,
                $right->externalEventKey,
            ]
        );

        $stepKeys = $this->deduplicateDirectSteps($events);

        return [
            'key' => $processKey,
            'name' => $this->humanize($processKey),
            'version' => 'draft',
            'steps' => array_map(
                fn (string $stepKey): array => [
                    'key' => $stepKey,
                    'name' => $this->humanize($stepKey),
                ],
                $stepKeys
            ),
            'transitions' => $this->transitions($stepKeys),
            'context_profile' => [
                'required' => [],
            ],
        ];
    }

    /**
     * @param array<int, DocumentTimelineEventRow> $events
     * @return array<int, string>
     */
    private function deduplicateDirectSteps(array $events): array
    {
        $stepKeys = [];
        $previousStepKey = null;

        foreach ($events as $event) {
            if ($event->stepKey === $previousStepKey) {
                continue;
            }

            $stepKeys[] = $event->stepKey;
            $previousStepKey = $event->stepKey;
        }

        return $stepKeys;
    }

    /**
     * @param array<int, string> $stepKeys
     * @return array<int, array{from: string, to: string}>
     */
    private function transitions(array $stepKeys): array
    {
        $transitions = [];
        for ($i = 0, $max = count($stepKeys) - 1; $i < $max; ++$i) {
            $transitions[] = [
                'from' => $stepKeys[$i],
                'to' => $stepKeys[$i + 1],
            ];
        }

        return $transitions;
    }

    private function humanize(string $key): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $key));
    }
}
