<?php

namespace App\Intelligence\Domain;

final class TimelineKpiEligibilityResolver
{
    /**
     * @param array<int, KpiTimelineEntry> $timeline
     * @param array<int, ProcessVersion> $processVersions
     */
    public function resolve(string $processKey, array $timeline, ?string $templateStartStep, array $processVersions): KpiEligibilityResult
    {
        $timeline = array_values($timeline);
        usort($timeline, static fn (KpiTimelineEntry $left, KpiTimelineEntry $right): int => $left->occurredAt <=> $right->occurredAt);

        $firstEvent = $timeline[0] ?? null;
        $lastEvent = $timeline === [] ? null : $timeline[count($timeline) - 1];
        $firstStep = $firstEvent?->stepKey;

        $versions = array_values(array_filter(
            $processVersions,
            static fn (ProcessVersion $version): bool => $version->processKey === $processKey
        ));
        usort($versions, static fn (ProcessVersion $left, ProcessVersion $right): int => $left->validFrom <=> $right->validFrom);

        if ($versions === []) {
            return new KpiEligibilityResult(false, null, KpiExclusionReason::NO_PROCESS_VERSION_DEFINED, $firstEvent?->occurredAt, $lastEvent?->occurredAt, $firstStep);
        }

        if ($firstEvent === null || $firstEvent->occurredAt < $versions[0]->validFrom) {
            return new KpiEligibilityResult(false, null, KpiExclusionReason::BEFORE_FIRST_BASELINE, $firstEvent?->occurredAt, $lastEvent?->occurredAt, $firstStep);
        }

        [$version, $nextVersion] = $this->versionWindow($firstEvent, $versions);
        if ($version === null) {
            return new KpiEligibilityResult(false, null, KpiExclusionReason::BEFORE_FIRST_BASELINE, $firstEvent->occurredAt, $lastEvent?->occurredAt, $firstStep);
        }

        if ($templateStartStep !== null && $templateStartStep !== '' && $firstStep !== $templateStartStep) {
            return new KpiEligibilityResult(false, $version, KpiExclusionReason::STARTED_MID_PROCESS, $firstEvent->occurredAt, $lastEvent?->occurredAt, $firstStep);
        }

        if ($nextVersion !== null && $lastEvent !== null && $lastEvent->occurredAt >= $nextVersion->validFrom) {
            return new KpiEligibilityResult(false, $version, KpiExclusionReason::CROSSED_VERSION_BOUNDARY, $firstEvent->occurredAt, $lastEvent->occurredAt, $firstStep, true);
        }

        return new KpiEligibilityResult(true, $version, null, $firstEvent->occurredAt, $lastEvent?->occurredAt, $firstStep);
    }

    /**
     * @param array<int, ProcessVersion> $versions
     * @return array{0: ProcessVersion|null, 1: ProcessVersion|null}
     */
    private function versionWindow(KpiTimelineEntry $firstEvent, array $versions): array
    {
        $current = null;
        $next = null;

        foreach ($versions as $index => $version) {
            $candidateNext = $versions[$index + 1] ?? null;
            if ($firstEvent->occurredAt >= $version->validFrom
                && ($candidateNext === null || $firstEvent->occurredAt < $candidateNext->validFrom)) {
                $current = $version;
                $next = $candidateNext;
                break;
            }
        }

        return [$current, $next];
    }
}
