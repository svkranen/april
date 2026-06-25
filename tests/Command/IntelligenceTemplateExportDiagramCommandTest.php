<?php

namespace App\Tests\Command;

use App\Command\IntelligenceTemplateExportDiagramCommand;
use App\Intelligence\Application\MermaidProcessGraphRenderer;
use App\Intelligence\Application\ProcessTemplateGraphFactory;
use App\Intelligence\Domain\ContextSnapshot;
use App\Intelligence\Domain\DocumentRef;
use App\Intelligence\Domain\ProcessEventRecord;
use App\Intelligence\Domain\ProcessVersion;
use App\Intelligence\Infrastructure\Process\InMemoryDocumentTimelineProvider;
use App\Intelligence\Infrastructure\Process\InMemoryProcessDocumentUuidProvider;
use App\Intelligence\Infrastructure\Process\InMemoryProcessVersionRepository;
use App\Intelligence\Application\KpiRelevantTimelineFilter;
use App\Intelligence\Template\TemplateDurationHeatmapBuilder;
use App\Intelligence\Template\TemplateFlowHeatmapBuilder;
use App\Intelligence\Template\TemplateHeatmapReportBuilder;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class IntelligenceTemplateExportDiagramCommandTest extends TestCase
{
    public function testExportsMermaidDiagramToStdout(): void
    {
        $tester = new CommandTester($this->command());

        $exitCode = $tester->execute([
            'template' => dirname(__DIR__, 2).'/config/april/process-templates/ai-rechnungen.yaml',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('flowchart TD', $tester->getDisplay());
        self::assertStringContainsString('n_parallel_start_buchen_und_zahlung{{buchen_und_zahlung<br/>start<br/>order:any}}:::constraint', $tester->getDisplay());
        self::assertStringContainsString('n_parallel_join_buchen_und_zahlung{{buchen_und_zahlung<br/>complete}}:::constraint', $tester->getDisplay());
        self::assertStringContainsString('n_parallel_join_buchen_und_zahlung --> n_09_Rechnungen_Abschluss', $tester->getDisplay());
        self::assertStringContainsString('n_decision_route_after_pruefung -->|"[2] amount_net &gt; 50"| n_03_Freigabe_klein', $tester->getDisplay());
        self::assertStringNotContainsString('default order', $tester->getDisplay());
    }

    public function testDoesNotExportDefaultOrderEdgesForTemplateWithExplicitTransitions(): void
    {
        $tester = new CommandTester($this->command());

        $exitCode = $tester->execute([
            'template' => dirname(__DIR__, 2).'/config/april/process-templates/ai-rechnungen.yaml',
            '--show-default-order' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringNotContainsString('default order', $tester->getDisplay());
        self::assertStringContainsString('n_decision_route_after_pruefung -->|"[2] amount_net &gt; 50"| n_03_Freigabe_klein', $tester->getDisplay());
        self::assertStringContainsString('n_02_Versenden --> n_parallel_start_buchen_und_zahlung', $tester->getDisplay());
        self::assertStringNotContainsString('n_02_Versenden --> n_parallel_join_buchen_und_zahlung', $tester->getDisplay());
    }

    public function testExportsObsidianCompatiblePriorityLabels(): void
    {
        $tester = new CommandTester($this->command());

        $exitCode = $tester->execute([
            'template' => dirname(__DIR__, 2).'/config/april/process-templates/ai-rechnungen.yaml',
            '--compat' => 'obsidian',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('n_decision_route_after_pruefung -->|"(2) amount_net &gt; 50"| n_03_Freigabe_klein', $tester->getDisplay());
        self::assertStringContainsString('n_decision_route_after_pruefung -->|"(else)"| n_parallel_start_buchen_und_zahlung', $tester->getDisplay());
        self::assertStringNotContainsString('n_decision_route_after_pruefung -->|"(else)"| n_05_Ausgangsrechnung_buchen', $tester->getDisplay());
        self::assertStringNotContainsString('-->|"[2]', $tester->getDisplay());
    }

    public function testExportsMermaidDiagramToFile(): void
    {
        $outputPath = sys_get_temp_dir().'/amagno-template-diagram-'.bin2hex(random_bytes(6)).'.mmd';
        $tester = new CommandTester($this->command());

        $exitCode = $tester->execute([
            'template' => dirname(__DIR__, 2).'/config/april/process-templates/ai-rechnungen.yaml',
            '--output' => $outputPath,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertFileExists($outputPath);
        self::assertStringContainsString('n_decision_route_after_pruefung -->|"[2] amount_net &gt; 50"| n_03_Freigabe_klein', (string) file_get_contents($outputPath));
    }

    public function testExportsFlowViewWithMetrics(): void
    {
        $metricsPath = sys_get_temp_dir().'/amagno-template-metrics-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($metricsPath, json_encode([
            'flow_heatmap' => [
                'transitions' => [
                    [
                        'from' => '02 Versenden',
                        'to' => 'parallel_start:buchen_und_zahlung',
                        'count' => 3,
                        'is_allowed' => true,
                    ],
                ],
            ],
        ]));
        $tester = new CommandTester($this->command());

        $exitCode = $tester->execute([
            'template' => dirname(__DIR__, 2).'/config/april/process-templates/ai-rechnungen.yaml',
            '--view' => 'flow',
            '--metrics' => $metricsPath,
        ]);

        unlink($metricsPath);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('n_02_Versenden -->|"count 3"| n_parallel_start_buchen_und_zahlung', $tester->getDisplay());
    }

    public function testExportsLiveMetricsFromProcessTimelineDocuments(): void
    {
        $events = [
            $this->event('900001', '00000000-0000-4000-8000-000000900001', 1, '01 Rechnungen pruefen', '2026-05-31T08:00:00+00:00'),
            $this->event('900001', '00000000-0000-4000-8000-000000900001', 2, '02 Versenden', '2026-05-31T08:05:00+00:00'),
            $this->event('900003', '00000000-0000-4000-8000-000000900003', 1, '01 Rechnungen pruefen', '2026-05-31T10:00:00+00:00', ['invoice_direction' => 'RE - Eingang', 'amount_net' => 400.0]),
            $this->event('900003', '00000000-0000-4000-8000-000000900003', 2, '03 Freigabe_klein', '2026-05-31T10:04:00+00:00', ['invoice_direction' => 'RE - Eingang', 'amount_net' => 400.0]),
        ];
        $snapshots = [
            $this->snapshot($events[0], ['invoice_direction' => 'RE - Ausgang', 'amount_net' => 400.0]),
            $this->snapshot($events[2], ['invoice_direction' => 'RE - Eingang', 'amount_net' => 400.0]),
        ];
        $tester = new CommandTester($this->command(
            new InMemoryProcessDocumentUuidProvider($events),
            new InMemoryDocumentTimelineProvider([], $events, $snapshots)
        ));

        $exitCode = $tester->execute([
            'template' => dirname(__DIR__, 2).'/config/april/process-templates/ai-rechnungen.yaml',
            '--view' => 'combined',
            '--debug-metrics' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('"documents_seen": 2', $tester->getDisplay());
        self::assertStringContainsString('"documents_projected": 2', $tester->getDisplay());
        self::assertStringContainsString('n_01_Rechnungen_pruefen -->|"count 2"| n_decision_route_after_pruefung', $tester->getDisplay());
        self::assertStringContainsString('n_decision_route_after_pruefung -->|"[1] invoice_direction = RE - Ausgang; count 1"| n_02_Versenden', $tester->getDisplay());
        self::assertStringContainsString('n_decision_route_after_pruefung -->|"[2] amount_net &gt; 50; count 1"| n_03_Freigabe_klein', $tester->getDisplay());
    }

    public function testLiveMetricsExposeEligibilitySummaryWithIncludeExcluded(): void
    {
        $events = [
            $this->event('900001', '00000000-0000-4000-8000-000000900001', 1, '01 Rechnungen pruefen', '2026-05-31T08:00:00+00:00'),
        ];
        $tester = new CommandTester($this->command(
            new InMemoryProcessDocumentUuidProvider($events),
            new InMemoryDocumentTimelineProvider([], $events),
            new KpiRelevantTimelineFilter(new InMemoryProcessVersionRepository())
        ));

        $exitCode = $tester->execute([
            'template' => dirname(__DIR__, 2).'/config/april/process-templates/ai-rechnungen.yaml',
            '--view' => 'flow',
            '--debug-metrics' => true,
            '--include-excluded' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('"excluded_instances": 1', $tester->getDisplay());
        self::assertStringContainsString('"no_process_version_defined": 1', $tester->getDisplay());
    }

    public function testLiveMetricsExcludeIneligibleTimelinesByDefault(): void
    {
        $events = [
            $this->event('900001', '00000000-0000-4000-8000-000000900001', 1, '03 Freigabe_klein', '2026-05-31T08:00:00+00:00'),
            $this->event('900002', '00000000-0000-4000-8000-000000900002', 1, '01 Rechnungen pruefen', '2026-05-31T09:00:00+00:00'),
        ];
        $tester = new CommandTester($this->command(
            new InMemoryProcessDocumentUuidProvider($events),
            new InMemoryDocumentTimelineProvider([], $events)
        ));

        $exitCode = $tester->execute([
            'template' => dirname(__DIR__, 2).'/config/april/process-templates/ai-rechnungen.yaml',
            '--view' => 'flow',
            '--debug-metrics' => true,
            '--show-node-metrics' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('"included_instances": 1', $tester->getDisplay());
        self::assertStringContainsString('"started_mid_process": 1', $tester->getDisplay());
        self::assertStringContainsString('n_01_Rechnungen_pruefen["01 Rechnungen pruefen<br/>docs: 1"]', $tester->getDisplay());
    }

    public function testLiveMetricsCanRestrictToLatestProcessVersion(): void
    {
        $events = [
            $this->event('900101', '00000000-0000-4000-8000-000000900101', 1, '01 Rechnungen pruefen', '2026-05-20T08:00:00+00:00'),
            $this->event('900101', '00000000-0000-4000-8000-000000900101', 2, '02 Versenden', '2026-05-20T08:05:00+00:00'),
            $this->event('900102', '00000000-0000-4000-8000-000000900102', 1, '01 Rechnungen pruefen', '2026-06-02T08:00:00+00:00'),
            $this->event('900102', '00000000-0000-4000-8000-000000900102', 2, '02 Versenden', '2026-06-02T08:05:00+00:00'),
        ];
        $snapshots = [
            $this->snapshot($events[0], ['invoice_direction' => 'RE - Ausgang', 'amount_net' => 400.0]),
            $this->snapshot($events[2], ['invoice_direction' => 'RE - Ausgang', 'amount_net' => 400.0]),
        ];
        $tester = new CommandTester($this->command(
            new InMemoryProcessDocumentUuidProvider($events),
            new InMemoryDocumentTimelineProvider([], $events, $snapshots),
            new KpiRelevantTimelineFilter(new InMemoryProcessVersionRepository([
                new ProcessVersion(null, 'ai-rechnungen', '1.0', new DateTimeImmutable('2026-05-01T00:00:00+00:00')),
                new ProcessVersion(null, 'ai-rechnungen', '1.1', new DateTimeImmutable('2026-06-01T00:00:00+00:00')),
            ]))
        ));

        $exitCode = $tester->execute([
            'template' => dirname(__DIR__, 2).'/config/april/process-templates/ai-rechnungen.yaml',
            '--view' => 'flow',
            '--process-version' => 'latest',
            '--debug-metrics' => true,
            '--show-node-metrics' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('"process_version_filter": "latest"', $tester->getDisplay());
        self::assertStringContainsString('"included_instances": 1', $tester->getDisplay());
        self::assertStringContainsString('"before_first_baseline": 1', $tester->getDisplay());
        self::assertStringContainsString('n_01_Rechnungen_pruefen["01 Rechnungen pruefen<br/>docs: 1"]', $tester->getDisplay());
    }

    public function testStandardDiagramDoesNotShowContextChangeAnnotations(): void
    {
        $events = [
            $this->event('900010', '00000000-0000-4000-8000-000000900010', 1, '01 Rechnungen pruefen', '2026-05-31T08:00:00+00:00'),
            $this->event('900010', '00000000-0000-4000-8000-000000900010', 2, '02 Versenden', '2026-05-31T08:05:00+00:00'),
        ];
        $snapshots = [
            $this->snapshot($events[0], ['invoice_direction' => 'RE - Eingang', 'amount_net' => 4149788]),
            $this->snapshot($events[1], ['invoice_direction' => 'RE - Eingang', 'amount_net' => 41.49]),
        ];
        $tester = new CommandTester($this->command(
            new InMemoryProcessDocumentUuidProvider($events),
            new InMemoryDocumentTimelineProvider([], $events, $snapshots)
        ));

        $exitCode = $tester->execute([
            'template' => dirname(__DIR__, 2).'/config/april/process-templates/ai-rechnungen.yaml',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringNotContainsString('Context changed', $tester->getDisplay());
        self::assertStringNotContainsString('classDef context-change', $tester->getDisplay());
    }

    public function testAuditDiagramShowsRelevantContextChangeAnnotationsForDecisionViolations(): void
    {
        $events = [
            $this->event('900010', '00000000-0000-4000-8000-000000900010', 1, '01 Rechnungen pruefen', '2026-05-31T08:00:00+00:00'),
            $this->event('900010', '00000000-0000-4000-8000-000000900010', 2, '02 Versenden', '2026-05-31T08:05:00+00:00'),
        ];
        $snapshots = [
            $this->snapshot($events[0], ['invoice_direction' => 'RE - Eingang', 'amount_net' => 4149788]),
            $this->snapshot($events[1], ['invoice_direction' => 'RE - Eingang', 'amount_net' => 41.49]),
        ];
        $tester = new CommandTester($this->command(
            new InMemoryProcessDocumentUuidProvider($events),
            new InMemoryDocumentTimelineProvider([], $events, $snapshots)
        ));

        $exitCode = $tester->execute([
            'template' => dirname(__DIR__, 2).'/config/april/process-templates/ai-rechnungen.yaml',
            '--diagram-mode' => 'audit',
        ]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Context changed<br/>amount_net: 4149788 -> 41.49<br/>affected decisions: route_after_pruefung', $display);
        self::assertStringContainsString('n_decision_route_after_pruefung -.-> n_context_change_1_decision_route_after_pruefung_amount_net', $display);
        self::assertStringContainsString('classDef context-change fill:#fef9c3,stroke:#ca8a04,stroke-dasharray: 4 4;', $display);
    }


    public function testExportsFlowViewWithNodeFlowMetrics(): void
    {
        $events = [
            $this->event('900001', '00000000-0000-4000-8000-000000900001', 1, '01 Rechnungen pruefen', '2026-05-31T08:00:00+00:00'),
            $this->event('900001', '00000000-0000-4000-8000-000000900001', 2, '02 Versenden', '2026-05-31T08:05:00+00:00'),
            $this->event('900001', '00000000-0000-4000-8000-000000900001', 3, '05 Ausgangsrechnung buchen', '2026-05-31T08:15:00+00:00'),
            $this->event('900003', '00000000-0000-4000-8000-000000900003', 1, '01 Rechnungen pruefen', '2026-05-31T10:00:00+00:00', ['invoice_direction' => 'RE - Eingang', 'amount_net' => 400.0]),
            $this->event('900003', '00000000-0000-4000-8000-000000900003', 2, '03 Freigabe_klein', '2026-05-31T10:04:00+00:00', ['invoice_direction' => 'RE - Eingang', 'amount_net' => 400.0]),
            $this->event('900003', '00000000-0000-4000-8000-000000900003', 3, '07 Zahlungseingang erwartet', '2026-05-31T10:14:00+00:00', ['invoice_direction' => 'RE - Eingang', 'amount_net' => 400.0]),
            $this->event('900003', '00000000-0000-4000-8000-000000900003', 4, '05 Ausgangsrechnung buchen', '2026-05-31T10:20:00+00:00', ['invoice_direction' => 'RE - Eingang', 'amount_net' => 400.0]),
        ];
        $snapshots = [
            $this->snapshot($events[0], ['invoice_direction' => 'RE - Ausgang', 'amount_net' => 400.0]),
            $this->snapshot($events[3], ['invoice_direction' => 'RE - Eingang', 'amount_net' => 400.0]),
            $this->snapshot($events[4], ['invoice_direction' => 'RE - Eingang', 'amount_net' => 400.0]),
        ];
        $tester = new CommandTester($this->command(
            new InMemoryProcessDocumentUuidProvider($events),
            new InMemoryDocumentTimelineProvider([], $events, $snapshots)
        ));

        $exitCode = $tester->execute([
            'template' => dirname(__DIR__, 2).'/config/april/process-templates/ai-rechnungen.yaml',
            '--view' => 'flow',
            '--show-flow-legend' => true,
            '--show-node-metrics' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('"flow_scale": "relative-percentile"', $tester->getDisplay());
        self::assertStringContainsString('Node color = relative document volume. Edge width = relative edge volume.', $tester->getDisplay());
        self::assertStringContainsString('Flow colors use a relative yellow-to-red percentile scale. Red means highest document volume in the current dataset, not critical.', $tester->getDisplay());
        self::assertMatchesRegularExpression('/n_01_Rechnungen_pruefen\["01 Rechnungen pruefen<br\/>docs: 2"\]:::required,flow-scale-[0-7]/', $tester->getDisplay());
        self::assertMatchesRegularExpression('/n_decision_route_after_pruefung\{route_after_pruefung<br\/>docs: 2\}:::flow-scale-[0-7]/', $tester->getDisplay());
        self::assertMatchesRegularExpression('/n_parallel_start_buchen_und_zahlung\{\{buchen_und_zahlung<br\/>start<br\/>order:any<br\/>docs: 2\}\}:::constraint,flow-scale-[0-7]/', $tester->getDisplay());
        self::assertMatchesRegularExpression('/n_parallel_join_buchen_und_zahlung\{\{buchen_und_zahlung<br\/>complete<br\/>docs: 1\}\}:::constraint,flow-scale-[0-7]/', $tester->getDisplay());
        self::assertStringContainsString('classDef flow-scale-7 fill:#fee2e2;', $tester->getDisplay());
    }

    public function testExportsDwellLegend(): void
    {
        $events = [
            $this->event('900001', '00000000-0000-4000-8000-000000900001', 1, '01 Rechnungen pruefen', '2026-05-31T08:00:00+00:00'),
            $this->event('900001', '00000000-0000-4000-8000-000000900001', 2, '02 Versenden', '2026-05-31T08:10:00+00:00'),
            $this->event('900001', '00000000-0000-4000-8000-000000900001', 3, '05 Ausgangsrechnung buchen', '2026-05-31T08:20:00+00:00'),
        ];
        $tester = new CommandTester($this->command(
            new InMemoryProcessDocumentUuidProvider($events),
            new InMemoryDocumentTimelineProvider([], $events, [
                $this->snapshot($events[0], ['invoice_direction' => 'RE - Ausgang', 'amount_net' => 400.0]),
            ])
        ));

        $exitCode = $tester->execute([
            'template' => dirname(__DIR__, 2).'/config/april/process-templates/ai-rechnungen.yaml',
            '--view' => 'dwell',
            '--show-dwell-legend' => true,
            '--dwell-metric' => 'median',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('"dwell_metric": "median"', $tester->getDisplay());
        self::assertStringContainsString('"dwell_scale": "relative-percentile"', $tester->getDisplay());
        self::assertStringContainsString('"color_space": "yellow-red"', $tester->getDisplay());
        self::assertStringContainsString('"dwell_buckets": 8', $tester->getDisplay());
        self::assertStringContainsString('"lower_percentile": "p10"', $tester->getDisplay());
        self::assertStringContainsString('"upper_percentile": "p90"', $tester->getDisplay());
        self::assertStringContainsString('"class": "dwell-scale-3"', $tester->getDisplay());
        self::assertStringContainsString('Neutral\/hellgelb = keine belastbare Dwell-Messung oder virtueller Prozessknoten.', $tester->getDisplay());
        self::assertStringContainsString('Node color = relative dwell time. Dwell colors use a relative yellow-to-red percentile scale. Red means longest dwell time in the current dataset, not automatically critical.', $tester->getDisplay());
        self::assertStringContainsString('"node_count": 1', $tester->getDisplay());
    }

    public function testLiveDwellMetricsKeepVirtualNodesNeutral(): void
    {
        $events = [
            $this->event('900003', '00000000-0000-4000-8000-000000900003', 1, '01 Rechnungen pruefen', '2026-05-31T10:00:00+00:00', ['invoice_direction' => 'RE - Eingang', 'amount_net' => 400.0]),
            $this->event('900003', '00000000-0000-4000-8000-000000900003', 2, '03 Freigabe_klein', '2026-05-31T10:04:00+00:00', ['invoice_direction' => 'RE - Eingang', 'amount_net' => 400.0]),
            $this->event('900003', '00000000-0000-4000-8000-000000900003', 3, '05 Ausgangsrechnung buchen', '2026-05-31T10:20:00+00:00', ['invoice_direction' => 'RE - Eingang', 'amount_net' => 400.0]),
            $this->event('900003', '00000000-0000-4000-8000-000000900003', 4, '07 Zahlungseingang erwartet', '2026-05-31T10:30:00+00:00', ['invoice_direction' => 'RE - Eingang', 'amount_net' => 400.0]),
            $this->event('900004', '00000000-0000-4000-8000-000000900004', 1, '01 Rechnungen pruefen', '2026-05-31T11:00:00+00:00', ['invoice_direction' => 'RE - Eingang', 'amount_net' => 1750.0]),
            $this->event('900004', '00000000-0000-4000-8000-000000900004', 2, '03 Freigabe_klein', '2026-05-31T11:05:00+00:00', ['invoice_direction' => 'RE - Eingang', 'amount_net' => 1750.0]),
            $this->event('900004', '00000000-0000-4000-8000-000000900004', 3, '04 Freigabe_gross', '2026-05-31T11:40:00+00:00', ['invoice_direction' => 'RE - Eingang', 'amount_net' => 1750.0]),
            $this->event('900004', '00000000-0000-4000-8000-000000900004', 4, '05 Ausgangsrechnung buchen', '2026-05-31T12:10:00+00:00', ['invoice_direction' => 'RE - Eingang', 'amount_net' => 1750.0]),
            $this->event('900004', '00000000-0000-4000-8000-000000900004', 5, '07 Zahlungseingang erwartet', '2026-05-31T12:15:00+00:00', ['invoice_direction' => 'RE - Eingang', 'amount_net' => 1750.0]),
        ];
        $snapshots = [
            $this->snapshot($events[0], ['invoice_direction' => 'RE - Eingang', 'amount_net' => 400.0]),
            $this->snapshot($events[1], ['invoice_direction' => 'RE - Eingang', 'amount_net' => 400.0]),
            $this->snapshot($events[4], ['invoice_direction' => 'RE - Eingang', 'amount_net' => 1750.0]),
            $this->snapshot($events[5], ['invoice_direction' => 'RE - Eingang', 'amount_net' => 1750.0]),
        ];
        $tester = new CommandTester($this->command(
            new InMemoryProcessDocumentUuidProvider($events),
            new InMemoryDocumentTimelineProvider([], $events, $snapshots)
        ));

        $exitCode = $tester->execute([
            'template' => dirname(__DIR__, 2).'/config/april/process-templates/ai-rechnungen.yaml',
            '--view' => 'dwell',
            '--debug-metrics' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('"virtual_node_duration_count": 8', $tester->getDisplay());
        self::assertStringContainsString('n_decision_route_after_pruefung{route_after_pruefung}:::no-dwell', $tester->getDisplay());
        self::assertStringContainsString('n_decision_freigabe_ab_1000{freigabe_ab_1000}:::no-dwell', $tester->getDisplay());
        self::assertStringContainsString('n_parallel_join_buchen_und_zahlung{{buchen_und_zahlung<br/>complete}}:::constraint,no-dwell', $tester->getDisplay());
        self::assertStringContainsString('n_parallel_start_buchen_und_zahlung{{buchen_und_zahlung<br/>start<br/>order:any}}:::constraint,no-dwell', $tester->getDisplay());
        self::assertStringContainsString('Neutral light yellow means no reliable dwell measurement is available or the node is virtual process structure.', $tester->getDisplay());
    }

    private function command(
        ?InMemoryProcessDocumentUuidProvider $documentUuidProvider = null,
        ?InMemoryDocumentTimelineProvider $timelineProvider = null,
        ?KpiRelevantTimelineFilter $timelineFilter = null
    ): IntelligenceTemplateExportDiagramCommand
    {
        return new IntelligenceTemplateExportDiagramCommand(
            new ProcessTemplateGraphFactory(),
            new MermaidProcessGraphRenderer(),
            null,
            new TemplateHeatmapReportBuilder(new TemplateFlowHeatmapBuilder(), new TemplateDurationHeatmapBuilder()),
            $documentUuidProvider,
            $timelineProvider,
            $timelineFilter ?? new KpiRelevantTimelineFilter(new InMemoryProcessVersionRepository([
                new ProcessVersion(null, 'ai-rechnungen', '1.0', new DateTimeImmutable('2026-01-01T00:00:00+00:00')),
            ]))
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function event(string $documentId, string $documentUuid, int $index, string $step, string $occurredAt, array $context = ['invoice_direction' => 'RE - Ausgang', 'amount_net' => 400.0]): ProcessEventRecord
    {
        $occurredAt = new DateTimeImmutable($occurredAt);
        $payload = [
            'step_key' => $step,
            'context' => $context,
        ];

        return new ProcessEventRecord(
            $index,
            sprintf('test-%s-%02d', $documentId, $index),
            'sample',
            'ai-rechnungen',
            $step,
            $step,
            $documentId,
            $documentUuid,
            1,
            null,
            $occurredAt,
            $occurredAt,
            json_encode($payload, JSON_THROW_ON_ERROR),
            json_encode($payload, JSON_THROW_ON_ERROR),
            null,
            'after'
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function snapshot(ProcessEventRecord $event, array $context): ContextSnapshot
    {
        return new ContextSnapshot(
            new DocumentRef($event->sourceSystem, $event->documentExternalId, $event->documentUuid, $event->documentVersion),
            $event->occurredAt,
            $context,
            [],
            $event->processKey,
            $event->externalEventKey,
            null,
            $event->occurredAt,
            $event->occurredAt,
            null,
            0,
            true
        );
    }
}
