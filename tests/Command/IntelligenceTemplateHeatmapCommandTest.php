<?php

namespace App\Tests\Command;

use App\Command\IntelligenceTemplateHeatmapCommand;
use App\Intelligence\Application\KpiRelevantTimelineFilter;
use App\Intelligence\Domain\ProcessEventRecord;
use App\Intelligence\Domain\ProcessVersion;
use App\Intelligence\Infrastructure\Process\InMemoryDocumentTimelineProvider;
use App\Intelligence\Infrastructure\Process\InMemoryProcessDocumentUuidProvider;
use App\Intelligence\Infrastructure\Process\InMemoryProcessVersionRepository;
use App\Intelligence\Template\TemplateDurationHeatmapBuilder;
use App\Intelligence\Template\TemplateFlowHeatmapBuilder;
use App\Intelligence\Template\TemplateHeatmapReportBuilder;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class IntelligenceTemplateHeatmapCommandTest extends TestCase
{
    public function testHeatmapExcludesIneligibleTimelinesByDefault(): void
    {
        $events = [
            $this->event('900001', 'uuid-900001', 1, '03 Freigabe_klein', '2026-06-01T09:00:00+00:00'),
            $this->event('900002', 'uuid-900002', 1, '01 Rechnungen pruefen', '2026-06-01T09:00:00+00:00'),
            $this->event('900002', 'uuid-900002', 2, '02 Versenden', '2026-06-01T09:05:00+00:00'),
        ];
        $tester = new CommandTester($this->command($events));

        $exitCode = $tester->execute([
            'processKey' => 'ai-rechnungen',
            '--template' => dirname(__DIR__, 2).'/config/april/process-templates/ai-rechnungen.yaml',
            '--format' => 'json',
        ]);
        $report = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(1, $report['documents_used']);
        self::assertSame(1, $report['kpi_eligibility']['included_instances']);
        self::assertSame(1, $report['kpi_eligibility']['excluded_instances']);
        self::assertSame(1, $report['kpi_eligibility']['exclusion_reasons']['started_mid_process']);
    }

    public function testHeatmapIncludeExcludedShowsReasonsAndIncludesDiagnostics(): void
    {
        $events = [
            $this->event('900001', 'uuid-900001', 1, '01 Rechnungen pruefen', '2026-06-01T09:00:00+00:00'),
        ];
        $tester = new CommandTester($this->command($events, []));

        $exitCode = $tester->execute([
            'processKey' => 'ai-rechnungen',
            '--template' => dirname(__DIR__, 2).'/config/april/process-templates/ai-rechnungen.yaml',
            '--format' => 'json',
            '--include-excluded' => true,
        ]);
        $report = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(1, $report['documents_used']);
        self::assertSame(0, $report['kpi_eligibility']['included_instances']);
        self::assertSame(1, $report['kpi_eligibility']['excluded_instances']);
        self::assertSame(1, $report['kpi_eligibility']['exclusion_reasons']['no_process_version_defined']);
        self::assertSame('no_process_version_defined', $report['kpi_eligibility']['excluded_timelines'][0]['exclusion_reason']);
    }

    public function testHeatmapUsesConfiguredProcessTemplateDirectoryByDefault(): void
    {
        $events = [
            $this->event('900001', 'uuid-900001', 1, '01 Rechnungen pruefen', '2026-06-01T09:00:00+00:00'),
        ];
        $tester = new CommandTester($this->command($events));

        $exitCode = $tester->execute([
            'processKey' => 'ai-rechnungen',
            '--format' => 'json',
            '--include-excluded' => true,
        ]);
        $report = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(1, $report['documents_used']);
    }

    /**
     * @param array<int, ProcessEventRecord> $events
     * @param array<int, ProcessVersion>|null $versions
     */
    private function command(array $events, ?array $versions = null): IntelligenceTemplateHeatmapCommand
    {
        return new IntelligenceTemplateHeatmapCommand(
            new TemplateHeatmapReportBuilder(new TemplateFlowHeatmapBuilder(), new TemplateDurationHeatmapBuilder()),
            new InMemoryProcessDocumentUuidProvider($events),
            new InMemoryDocumentTimelineProvider([], $events),
            new KpiRelevantTimelineFilter(new InMemoryProcessVersionRepository($versions ?? [
                new ProcessVersion(null, 'ai-rechnungen', '1.0', new DateTimeImmutable('2026-06-01T08:00:00+00:00')),
            ])),
            dirname(__DIR__, 2).'/config/april/process-templates'
        );
    }

    private function event(string $documentId, string $documentUuid, int $index, string $step, string $occurredAt): ProcessEventRecord
    {
        $occurredAt = new DateTimeImmutable($occurredAt);

        return new ProcessEventRecord(
            null,
            sprintf('evt-%s-%d', $documentId, $index),
            'amagno',
            'ai-rechnungen',
            $step,
            $step,
            $documentId,
            $documentUuid,
            1,
            'user-1',
            $occurredAt,
            $occurredAt,
            '{}',
            '{}'
        );
    }
}
