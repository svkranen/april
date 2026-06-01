<?php

namespace App\Tests\Intelligence\Domain;

use App\Intelligence\Domain\KpiExclusionReason;
use App\Intelligence\Domain\KpiTimelineEntry;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateStep;
use App\Intelligence\Domain\ProcessVersion;
use App\Intelligence\Domain\TimelineKpiEligibilityResolver;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class TimelineKpiEligibilityResolverTest extends TestCase
{
    public function testNoProcessVersionIsExcluded(): void
    {
        $result = $this->resolver()->resolve('ai-rechnungen', [
            $this->entry('01 Eingang', '2026-06-01T09:00:00+00:00'),
        ], '01 Eingang', []);

        self::assertFalse($result->isEligible);
        self::assertSame(KpiExclusionReason::NO_PROCESS_VERSION_DEFINED, $result->exclusionReason);
    }

    public function testEventBeforeFirstBaselineIsExcluded(): void
    {
        $result = $this->resolver()->resolve('ai-rechnungen', [
            $this->entry('01 Eingang', '2026-05-31T12:00:00+00:00'),
        ], '01 Eingang', [
            $this->version('1.0', '2026-06-01T08:00:00+00:00'),
        ]);

        self::assertFalse($result->isEligible);
        self::assertSame(KpiExclusionReason::BEFORE_FIRST_BASELINE, $result->exclusionReason);
    }

    public function testCleanStartAfterBaselineIsEligible(): void
    {
        $result = $this->resolver()->resolve('ai-rechnungen', [
            $this->entry('01 Eingang', '2026-06-01T09:00:00+00:00'),
            $this->entry('02 Pruefung', '2026-06-01T10:00:00+00:00'),
        ], '01 Eingang', [
            $this->version('1.0', '2026-06-01T08:00:00+00:00'),
        ]);

        self::assertTrue($result->isEligible);
        self::assertSame('1.0', $result->processVersion?->version);
        self::assertNull($result->exclusionReason);
    }

    public function testStartedMidProcessIsExcluded(): void
    {
        $result = $this->resolver()->resolve('ai-rechnungen', [
            $this->entry('03 Freigabe', '2026-06-01T09:00:00+00:00'),
        ], '01 Eingang', [
            $this->version('1.0', '2026-06-01T08:00:00+00:00'),
        ]);

        self::assertFalse($result->isEligible);
        self::assertSame(KpiExclusionReason::STARTED_MID_PROCESS, $result->exclusionReason);
    }

    public function testCrossedVersionBoundaryIsExcluded(): void
    {
        $result = $this->resolver()->resolve('ai-rechnungen', [
            $this->entry('01 Eingang', '2026-06-30T10:00:00+00:00'),
            $this->entry('02 Pruefung', '2026-07-01T12:00:00+00:00'),
        ], '01 Eingang', [
            $this->version('1.0', '2026-06-01T08:00:00+00:00'),
            $this->version('1.1', '2026-07-01T08:00:00+00:00'),
        ]);

        self::assertFalse($result->isEligible);
        self::assertSame(KpiExclusionReason::CROSSED_VERSION_BOUNDARY, $result->exclusionReason);
        self::assertTrue($result->crossedVersionBoundary);
    }

    public function testNewVersionOnlyCountsNewlyStartedTimelines(): void
    {
        $result = $this->resolver()->resolve('ai-rechnungen', [
            $this->entry('01 Eingang', '2026-06-30T10:00:00+00:00'),
            $this->entry('02 Pruefung', '2026-07-01T12:00:00+00:00'),
        ], '01 Eingang', [
            $this->version('1.1', '2026-07-01T08:00:00+00:00'),
        ]);

        self::assertFalse($result->isEligible);
        self::assertSame(KpiExclusionReason::BEFORE_FIRST_BASELINE, $result->exclusionReason);
    }

    public function testTemplateWithoutInitialStepUsesFirstTemplateStepFallback(): void
    {
        $template = new ProcessTemplate(
            'ai-rechnungen',
            steps: [
                new ProcessTemplateStep('01 Eingang'),
                new ProcessTemplateStep('02 Pruefung'),
            ]
        );
        $startStep = $template->initialStepKey ?? $template->steps[0]->key ?? null;

        $result = $this->resolver()->resolve('ai-rechnungen', [
            $this->entry('01 Eingang', '2026-06-01T09:00:00+00:00'),
        ], $startStep, [
            $this->version('1.0', '2026-06-01T08:00:00+00:00'),
        ]);

        self::assertTrue($result->isEligible);
    }

    private function resolver(): TimelineKpiEligibilityResolver
    {
        return new TimelineKpiEligibilityResolver();
    }

    private function entry(string $step, string $occurredAt): KpiTimelineEntry
    {
        return new KpiTimelineEntry($step, new DateTimeImmutable($occurredAt));
    }

    private function version(string $version, string $validFrom): ProcessVersion
    {
        return new ProcessVersion(null, 'ai-rechnungen', $version, new DateTimeImmutable($validFrom));
    }
}
