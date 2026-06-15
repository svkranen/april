<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\KpiRelevantTimelineFilter;
use App\Intelligence\Domain\KpiExclusionReason;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateStep;
use App\Intelligence\Domain\ProcessVersion;
use App\Intelligence\Infrastructure\Process\InMemoryProcessVersionRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class KpiRelevantTimelineFilterTest extends TestCase
{
    public function testFiltersOutExcludedTimelinesByDefaultAndReportsReasons(): void
    {
        $filter = new KpiRelevantTimelineFilter(new InMemoryProcessVersionRepository([
            new ProcessVersion(null, 'ai-rechnungen', '1.0', new DateTimeImmutable('2026-06-01T08:00:00+00:00')),
        ]));

        $result = $filter->filterDocumentTimelines($this->template(), 'ai-rechnungen', [
            $this->timeline('doc-1', '01 Eingang', '2026-06-01T09:00:00+00:00'),
            $this->timeline('doc-2', '03 Freigabe', '2026-06-01T09:00:00+00:00'),
        ]);

        self::assertCount(1, $result->included);
        self::assertSame('doc-1', $result->included[0]['document_uuid']);
        self::assertSame(1, $result->summary['included_instances']);
        self::assertSame(1, $result->summary['excluded_instances']);
        self::assertSame([KpiExclusionReason::STARTED_MID_PROCESS => 1], $result->summary['exclusion_reasons']);
    }

    public function testIncludeExcludedKeepsTimelinesVisibleWithDiagnosticSummary(): void
    {
        $filter = new KpiRelevantTimelineFilter(new InMemoryProcessVersionRepository());

        $result = $filter->filterDocumentTimelines($this->template(), 'ai-rechnungen', [
            $this->timeline('doc-1', '01 Eingang', '2026-06-01T09:00:00+00:00'),
        ], true);

        self::assertCount(1, $result->included);
        self::assertCount(1, $result->excluded);
        self::assertSame(KpiExclusionReason::NO_PROCESS_VERSION_DEFINED, $result->excluded[0]['exclusion_reason']);
        self::assertSame([KpiExclusionReason::NO_PROCESS_VERSION_DEFINED => 1], $result->summary['exclusion_reasons']);
    }

    public function testLatestProcessVersionFilterOnlyIncludesTimelinesStartedAfterLatestBaseline(): void
    {
        $filter = new KpiRelevantTimelineFilter(new InMemoryProcessVersionRepository([
            new ProcessVersion(null, 'ai-rechnungen', '1.0', new DateTimeImmutable('2026-05-01T00:00:00+00:00')),
            new ProcessVersion(null, 'ai-rechnungen', '1.1', new DateTimeImmutable('2026-06-01T00:00:00+00:00')),
        ]));

        $result = $filter->filterDocumentTimelines($this->template(), 'ai-rechnungen', [
            $this->timeline('doc-old', '01 Eingang', '2026-05-20T09:00:00+00:00'),
            $this->timeline('doc-latest', '01 Eingang', '2026-06-02T09:00:00+00:00'),
        ], false, 'latest');

        self::assertCount(1, $result->included);
        self::assertSame('doc-latest', $result->included[0]['document_uuid']);
        self::assertSame('latest', $result->summary['process_version_filter']);
        self::assertSame([KpiExclusionReason::BEFORE_FIRST_BASELINE => 1], $result->summary['exclusion_reasons']);
    }

    private function template(): ProcessTemplate
    {
        return new ProcessTemplate(
            'ai-rechnungen',
            steps: [
                new ProcessTemplateStep('01 Eingang'),
                new ProcessTemplateStep('03 Freigabe'),
            ]
        );
    }

    /**
     * @return array{document_uuid: string, timeline: array<int, array{step: string, occurred_at: string}>}
     */
    private function timeline(string $documentUuid, string $step, string $occurredAt): array
    {
        return [
            'document_uuid' => $documentUuid,
            'timeline' => [
                ['step' => $step, 'occurred_at' => $occurredAt],
            ],
        ];
    }
}
