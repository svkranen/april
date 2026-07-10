<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\JourneyDocumentCandidateProvider;
use App\Intelligence\Application\JourneyDocumentCheckService;
use App\Intelligence\Application\JourneyMatchPreviewService;
use App\Intelligence\Application\JourneyTemplateCheckService;
use App\Intelligence\Domain\ProcessEventRecord;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateMatch;
use App\Intelligence\Domain\ProcessTemplateStep;
use App\Intelligence\Infrastructure\Process\InMemoryDocumentTimelineProvider;
use App\Intelligence\Infrastructure\Process\InMemoryProcessDocumentUuidProvider;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class JourneyMatchPreviewServiceTest extends TestCase
{
    public function testPreviewWithoutOverrideUsesSavedMatch(): void
    {
        $service = $this->service([
            $this->event(1, 'uuid-1', 'aufmass', '2026-06-01T09:00:00+00:00'),
        ]);

        $report = $service->preview($this->journeyTemplate(['aufmass']));

        self::assertSame(['aufmass'], $report->matchProcessKeys);
        self::assertCount(1, $report->rows);
        self::assertSame('uuid-1', $report->rows[0]->documentRef->documentUuid);
        self::assertSame(JourneyTemplateCheckService::STATUS_SATISFIED, $report->rows[0]->status());
    }

    public function testOverrideWithNewKeysChangesCandidatesWithoutMutatingTemplate(): void
    {
        $service = $this->service([
            $this->event(1, 'uuid-1', 'aufmass', '2026-06-01T09:00:00+00:00'),
            $this->event(2, 'uuid-2', 'service', '2026-06-01T10:00:00+00:00'),
        ]);
        $template = $this->journeyTemplate(['aufmass']);

        $report = $service->preview($template, ['service']);

        self::assertSame(['service'], $report->matchProcessKeys);
        self::assertSame(['uuid-2'], array_map(
            static fn ($row): string => $row->documentRef->documentUuid,
            $report->rows
        ));
        // The stored template state stays untouched - preview only.
        self::assertSame(['aufmass'], $template->match?->anyProcessKeys);
    }

    public function testOverrideWithUnknownKeyYieldsNoCandidates(): void
    {
        $service = $this->service([
            $this->event(1, 'uuid-1', 'aufmass', '2026-06-01T09:00:00+00:00'),
        ]);

        $report = $service->preview($this->journeyTemplate(['aufmass']), ['does-not-exist']);

        self::assertSame(['does-not-exist'], $report->matchProcessKeys);
        self::assertSame([], $report->rows);
    }

    public function testEmptyOverrideFallsBackToLegacyRequiredStepMatching(): void
    {
        $service = $this->service([
            $this->event(1, 'uuid-1', 'aufmass', '2026-06-01T09:00:00+00:00'),
        ]);

        // Template has an explicit match; an empty override removes it, so the
        // resolver's legacy fallback (first required process step) must apply.
        $report = $service->preview($this->journeyTemplate(['aufmass']), []);

        self::assertSame(['aufmass'], $report->matchProcessKeys);
        self::assertNotSame([], $report->warnings);
        self::assertStringContainsString('legacy fallback', $report->warnings[0]);
    }

    public function testEmptyOverrideWithoutRequiredStepIsNotMatchable(): void
    {
        $service = $this->service([
            $this->event(1, 'uuid-1', 'aufmass', '2026-06-01T09:00:00+00:00'),
        ]);
        $template = new ProcessTemplate(
            'journey-without-required',
            scope: 'journey',
            match: new ProcessTemplateMatch(['aufmass']),
            steps: [
                new ProcessTemplateStep('intake', type: 'process', processKey: 'aufmass', required: false),
            ]
        );

        $report = $service->preview($template, []);

        self::assertSame([], $report->matchProcessKeys);
        self::assertSame([], $report->rows);
        self::assertStringContainsString('no explicit match.any_process', $report->warnings[0]);
    }

    public function testJourneyWithoutCandidatesReturnsEmptyRows(): void
    {
        $service = $this->service([]);

        $report = $service->preview($this->journeyTemplate(['aufmass']));

        self::assertSame(['aufmass'], $report->matchProcessKeys);
        self::assertSame([], $report->rows);
    }

    /**
     * @param array<int, string> $matchKeys
     */
    private function journeyTemplate(array $matchKeys): ProcessTemplate
    {
        return new ProcessTemplate(
            'test-journey',
            scope: 'journey',
            match: new ProcessTemplateMatch($matchKeys),
            steps: [
                new ProcessTemplateStep('aufmass_step', type: 'process', processKey: 'aufmass', required: true),
            ]
        );
    }

    /**
     * @param array<int, ProcessEventRecord> $events
     */
    private function service(array $events): JourneyMatchPreviewService
    {
        return new JourneyMatchPreviewService(new JourneyDocumentCheckService(
            new JourneyDocumentCandidateProvider(new InMemoryProcessDocumentUuidProvider($events)),
            new JourneyTemplateCheckService(new InMemoryDocumentTimelineProvider([], $events))
        ));
    }

    private function event(int $id, string $documentUuid, string $processKey, string $occurredAt): ProcessEventRecord
    {
        $time = new DateTimeImmutable($occurredAt);

        return new ProcessEventRecord(
            $id,
            sprintf('evt-%d', $id),
            'test',
            $processKey,
            'start',
            'start',
            'doc-'.$documentUuid,
            $documentUuid,
            1,
            'user-1',
            $time,
            $time,
            '{}',
            '{}',
            1
        );
    }
}
