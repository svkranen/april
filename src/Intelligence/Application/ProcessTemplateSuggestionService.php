<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateStep;
use App\Intelligence\Domain\ProcessTemplateSuggestionNote;
use App\Intelligence\Domain\ProcessTemplateSuggestionResult;
use App\Intelligence\Domain\ProcessTemplateTransition;

final class ProcessTemplateSuggestionService
{
    public function __construct(
        private readonly DocumentTimelineProvider $timelineProvider
    ) {
    }

    public function suggest(
        string $documentUuid,
        string $processKey,
        ?int $documentVersion = null,
        bool $includeBefore = false,
        EventTimelineOrder $order = EventTimelineOrder::DEFAULT
    ): ?ProcessTemplateSuggestionResult
    {
        $events = array_values(array_filter(
            $this->timelineProvider->build($documentUuid, $order)->events,
            static fn (DocumentTimelineEventRow $event): bool => $event->processKey === $processKey
                && ($includeBefore || $event->eventPhase === 'after')
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

        usort($events, static fn (DocumentTimelineEventRow $left, DocumentTimelineEventRow $right): int => $order->compareTimelineRows($left, $right));

        $stepKeys = $this->deduplicateDirectSteps($events);

        return new ProcessTemplateSuggestionResult(
            new ProcessTemplate(
                $processKey,
                'draft',
                $this->humanize($processKey),
                steps: array_map(
                    fn (string $stepKey): ProcessTemplateStep => new ProcessTemplateStep(
                        $stepKey,
                        $this->humanize($stepKey)
                    ),
                    $stepKeys
                ),
                transitions: $this->transitions($stepKeys),
                contextProfileRequiredFields: []
            ),
            [],
            [],
            [],
            $this->repeatedEventSuggestions($documentUuid, $events)
        );
    }

    /**
     * @param array<int, DocumentTimelineEventRow> $events
     * @return array<int, string>
     */
    private function deduplicateDirectSteps(array $events): array
    {
        $stepKeys = [];
        $previousNormalizedStepKey = null;

        foreach ($events as $event) {
            $normalizedStepKey = StepKeyNormalizer::normalize($event->stepKey);
            if ($normalizedStepKey === $previousNormalizedStepKey) {
                continue;
            }

            $stepKeys[] = $event->stepKey;
            $previousNormalizedStepKey = $normalizedStepKey;
        }

        return $stepKeys;
    }

    /**
     * @param array<int, string> $stepKeys
     * @return array<int, ProcessTemplateTransition>
     */
    private function transitions(array $stepKeys): array
    {
        $transitions = [];
        for ($i = 0, $max = count($stepKeys) - 1; $i < $max; ++$i) {
            $transitions[] = new ProcessTemplateTransition($stepKeys[$i], $stepKeys[$i + 1]);
        }

        return $transitions;
    }

    private function humanize(string $key): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $key));
    }

    /**
     * @param array<int, DocumentTimelineEventRow> $events
     * @return array<int, ProcessTemplateSuggestionNote>
     */
    private function repeatedEventSuggestions(string $documentUuid, array $events): array
    {
        $rawSequence = array_map(
            static fn (DocumentTimelineEventRow $event): array => [
                'key' => $event->stepKey,
                'normalized' => StepKeyNormalizer::normalize($event->stepKey),
            ],
            $events
        );
        $suggestions = [];

        for ($index = 0, $count = count($rawSequence); $index < $count; ++$index) {
            $runStart = $index;
            $normalized = $rawSequence[$index]['normalized'];
            while ($index + 1 < $count && $rawSequence[$index + 1]['normalized'] === $normalized) {
                ++$index;
            }

            $runLength = $index - $runStart + 1;
            if ($runLength < 2) {
                continue;
            }

            $previous = $rawSequence[$runStart - 1]['key'] ?? null;
            $following = $rawSequence[$index + 1]['key'] ?? null;
            $suggestions[] = new ProcessTemplateSuggestionNote(
                'possible_multi_approval',
                'Möglicher dynamischer Mehrpersonenfreigabe-Prozess. Bitte prüfen, ob hierfür ein contextbasierter signCheck definiert werden soll.',
                documentUuids: [$documentUuid],
                eventKey: $rawSequence[$runStart]['key'],
                affectedDocuments: 1,
                minRepetitions: $runLength,
                maxRepetitions: $runLength,
                avgRepetitions: (float) $runLength,
                previousEvents: $previous === null ? [] : [['event_key' => $previous, 'count' => 1]],
                followingEvents: $following === null ? [] : [['event_key' => $following, 'count' => 1]]
            );
        }

        return $suggestions;
    }
}
