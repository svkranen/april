<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\KpiTimelineEntry;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\TimelineKpiEligibilityResolver;
use DateTimeImmutable;

final readonly class KpiRelevantTimelineFilter
{
    public function __construct(
        private ProcessVersionRepository $processVersionRepository,
        private TimelineKpiEligibilityResolver $resolver = new TimelineKpiEligibilityResolver()
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $documentTimelines
     */
    public function filterDocumentTimelines(
        ProcessTemplate $template,
        string $processKey,
        array $documentTimelines,
        bool $includeExcluded = false,
        ?string $processVersion = null
    ): KpiTimelineFilterResult {
        return $this->filter(
            $template,
            $processKey,
            $documentTimelines,
            static fn (array $documentTimeline): array => self::entriesFromTimeline($documentTimeline['timeline'] ?? []),
            $includeExcluded,
            $processVersion
        );
    }

    /**
     * @param array<int, array<int, DocumentTimelineEventRow>> $eventGroups
     */
    public function filterEventGroups(
        ProcessTemplate $template,
        string $processKey,
        array $eventGroups,
        bool $includeExcluded = false,
        ?string $processVersion = null
    ): KpiTimelineFilterResult {
        return $this->filter(
            $template,
            $processKey,
            $eventGroups,
            static fn (array $events): array => array_map(
                static fn (DocumentTimelineEventRow $event): KpiTimelineEntry => new KpiTimelineEntry($event->stepKey, $event->occurredAt),
                $events
            ),
            $includeExcluded,
            $processVersion
        );
    }

    /**
     * @param array<int, mixed> $items
     * @param callable(mixed): array<int, KpiTimelineEntry> $timelineFactory
     */
    private function filter(
        ProcessTemplate $template,
        string $processKey,
        array $items,
        callable $timelineFactory,
        bool $includeExcluded = false,
        ?string $processVersion = null
    ): KpiTimelineFilterResult {
        $versions = $this->versionsForFilter($processKey, $processVersion);
        $startStep = $this->startStep($template);
        $included = [];
        $excluded = [];
        $reasonCounts = [];

        foreach ($items as $item) {
            $result = $this->resolver->resolve($processKey, $timelineFactory($item), $startStep, $versions);
            if ($result->isEligible || $includeExcluded) {
                $included[] = $item;
            }

            if (!$result->isEligible) {
                $reason = $result->exclusionReason ?? 'unknown';
                $reasonCounts[$reason] = ($reasonCounts[$reason] ?? 0) + 1;
                $excluded[] = [
                    'document_uuid' => is_array($item) ? ($item['document_uuid'] ?? null) : null,
                    'document_id' => is_array($item) ? ($item['document_id'] ?? null) : null,
                    'exclusion_reason' => $reason,
                    'process_version' => $result->processVersion?->version,
                    'first_event_at' => $result->firstEventAt?->format(DATE_ATOM),
                    'last_event_at' => $result->lastEventAt?->format(DATE_ATOM),
                    'first_step' => $result->firstStep,
                    'crossed_version_boundary' => $result->crossedVersionBoundary,
                ];
            }
        }

        ksort($reasonCounts);

        return new KpiTimelineFilterResult(
            $included,
            $excluded,
            [
                'included_instances' => count($items) - count($excluded),
                'excluded_instances' => count($excluded),
                'exclusion_reasons' => $reasonCounts,
                'include_excluded' => $includeExcluded,
                'process_version_filter' => $processVersion,
            ]
        );
    }

    /**
     * @return array<int, \App\Intelligence\Domain\ProcessVersion>
     */
    private function versionsForFilter(string $processKey, ?string $processVersion): array
    {
        if ($processVersion === null || trim($processVersion) === '') {
            return $this->processVersionRepository->findByProcessKey($processKey);
        }

        $processVersion = trim($processVersion);
        $version = $processVersion === 'latest'
            ? $this->processVersionRepository->latestForProcess($processKey)
            : $this->processVersionRepository->findOneByProcessKeyAndVersion($processKey, $processVersion);

        return $version === null ? [] : [$version];
    }

    private function startStep(ProcessTemplate $template): ?string
    {
        return $template->initialStepKey ?? $template->steps[0]->key ?? null;
    }

    /**
     * @param mixed $timeline
     * @return array<int, KpiTimelineEntry>
     */
    private static function entriesFromTimeline(mixed $timeline): array
    {
        if (!is_array($timeline)) {
            return [];
        }

        $entries = [];
        foreach ($timeline as $entry) {
            if (!is_array($entry) || !isset($entry['step'], $entry['occurred_at'])) {
                continue;
            }

            $occurredAt = $entry['occurred_at'] instanceof DateTimeImmutable
                ? $entry['occurred_at']
                : new DateTimeImmutable((string) $entry['occurred_at']);
            $entries[] = new KpiTimelineEntry((string) $entry['step'], $occurredAt);
        }

        return $entries;
    }
}
