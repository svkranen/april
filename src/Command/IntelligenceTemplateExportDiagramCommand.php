<?php

namespace App\Command;

use App\Intelligence\Application\DocumentTimelineEventRow;
use App\Intelligence\Application\DocumentTimelineProvider;
use App\Intelligence\Application\EventTimelineOrder;
use App\Intelligence\Application\KpiRelevantTimelineFilter;
use App\Intelligence\Application\MermaidProcessGraphRenderer;
use App\Intelligence\Application\MermaidProcessGraphRenderOptions;
use App\Intelligence\Application\ObservedTransitionProjection;
use App\Intelligence\Application\ProcessGraphMetricsFactory;
use App\Intelligence\Application\ProcessGraphObservationProjector;
use App\Intelligence\Application\ProcessDocumentRef;
use App\Intelligence\Application\ProcessDocumentUuidProvider;
use App\Intelligence\Application\ProcessTemplateCheckService;
use App\Intelligence\Application\ProcessTemplateGraphFactory;
use App\Intelligence\Domain\ProcessTemplateArrayFactory;
use App\Intelligence\Template\TemplateHeatmapReportBuilder;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(
    name: 'intelligence:template:export-diagram',
    description: 'Exports a process template diagram.'
)]
final class IntelligenceTemplateExportDiagramCommand extends Command
{
    private ProcessGraphMetricsFactory $metricsFactory;

    public function __construct(
        private readonly ProcessTemplateGraphFactory $graphFactory,
        private readonly MermaidProcessGraphRenderer $mermaidRenderer,
        ?ProcessGraphMetricsFactory $metricsFactory = null,
        private readonly ?TemplateHeatmapReportBuilder $heatmapReportBuilder = null,
        private readonly ?ProcessDocumentUuidProvider $documentUuidProvider = null,
        private readonly ?DocumentTimelineProvider $timelineProvider = null,
        private readonly ?KpiRelevantTimelineFilter $timelineFilter = null,
        private readonly ?ProcessTemplateCheckService $checkService = null,
        private readonly ProcessGraphObservationProjector $observationProjector = new ProcessGraphObservationProjector()
    ) {
        $this->metricsFactory = $metricsFactory ?? new ProcessGraphMetricsFactory();
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('template', InputArgument::REQUIRED, 'Path to the YAML process template')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: mermaid', 'mermaid')
            ->addOption('view', null, InputOption::VALUE_REQUIRED, 'Diagram view: structure, flow, dwell, deviations or combined', MermaidProcessGraphRenderOptions::VIEW_STRUCTURE)
            ->addOption('metrics', null, InputOption::VALUE_REQUIRED, 'Optional JSON or YAML metrics/heatmap report path')
            ->addOption('heatmap', null, InputOption::VALUE_REQUIRED, 'Alias for --metrics')
            ->addOption('process-key', null, InputOption::VALUE_REQUIRED, 'Process key for live metrics. Defaults to the template key')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Only include timeline events at or after this datetime')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Only include timeline events at or before this datetime')
            ->addOption('document-id', null, InputOption::VALUE_REQUIRED, 'Restrict live metrics to one document external ID or UUID')
            ->addOption('document-version', null, InputOption::VALUE_REQUIRED, 'Document version for live metrics')
            ->addOption('sample-only', null, InputOption::VALUE_NONE, 'Restrict live metrics to local sample documents')
            ->addOption('include-deviations', null, InputOption::VALUE_NONE, 'When set with --include-ok, include deviation documents in live metrics')
            ->addOption('include-ok', null, InputOption::VALUE_NONE, 'When set with --include-deviations, include OK documents in live metrics')
            ->addOption('include-excluded', null, InputOption::VALUE_NONE, 'Include timelines excluded from standard KPI/heatmap eligibility and report exclusion reasons in live metrics')
            ->addOption('debug-metrics', null, InputOption::VALUE_NONE, 'Print live metrics source/projection diagnostics before the Mermaid output')
            ->addOption('dwell-metric', null, InputOption::VALUE_REQUIRED, 'Dwell metric for node buckets: avg, median or p95', MermaidProcessGraphRenderOptions::DWELL_METRIC_MEDIAN)
            ->addOption('dwell-buckets', null, InputOption::VALUE_REQUIRED, 'Number of relative percentile dwell buckets', '8')
            ->addOption('show-dwell-legend', null, InputOption::VALUE_NONE, 'Print dwell bucket legend and node counts before the Mermaid output')
            ->addOption('flow-buckets', null, InputOption::VALUE_REQUIRED, 'Number of relative percentile flow buckets', '8')
            ->addOption('show-flow-legend', null, InputOption::VALUE_NONE, 'Print flow bucket legend and node counts before the Mermaid output')
            ->addOption('show-node-metrics', null, InputOption::VALUE_NONE, 'Show node metric labels, such as docs count in flow view')
            ->addOption('order-by', null, InputOption::VALUE_REQUIRED, 'Event order for live metrics: occurred-at, received-at, or occurred-then-received', EventTimelineOrder::DEFAULT->value)
            ->addOption('compat', null, InputOption::VALUE_REQUIRED, 'Compatibility mode: default or obsidian', MermaidProcessGraphRenderOptions::COMPAT_DEFAULT)
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Optional output file path')
            ->addOption('show-default-order', null, InputOption::VALUE_NONE, 'Render implicit step sequence edges as dashed default-order edges');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = strtolower((string) $input->getOption('format'));
        if ($format !== 'mermaid') {
            $output->writeln('<error>Invalid --format. Use mermaid.</error>');

            return Command::INVALID;
        }
        $view = strtolower((string) $input->getOption('view'));
        if (!in_array($view, [
            MermaidProcessGraphRenderOptions::VIEW_STRUCTURE,
            MermaidProcessGraphRenderOptions::VIEW_FLOW,
            MermaidProcessGraphRenderOptions::VIEW_DWELL,
            MermaidProcessGraphRenderOptions::VIEW_DEVIATIONS,
            MermaidProcessGraphRenderOptions::VIEW_COMBINED,
        ], true)) {
            $output->writeln('<error>Invalid --view. Use structure, flow, dwell, deviations or combined.</error>');

            return Command::INVALID;
        }
        $compatibility = strtolower((string) $input->getOption('compat'));
        if (!in_array($compatibility, [MermaidProcessGraphRenderOptions::COMPAT_DEFAULT, MermaidProcessGraphRenderOptions::COMPAT_OBSIDIAN], true)) {
            $output->writeln('<error>Invalid --compat. Use default or obsidian.</error>');

            return Command::INVALID;
        }
        $dwellMetric = strtolower((string) $input->getOption('dwell-metric'));
        if (!in_array($dwellMetric, [
            MermaidProcessGraphRenderOptions::DWELL_METRIC_AVG,
            MermaidProcessGraphRenderOptions::DWELL_METRIC_MEDIAN,
            MermaidProcessGraphRenderOptions::DWELL_METRIC_P95,
        ], true)) {
            $output->writeln('<error>Invalid --dwell-metric. Use avg, median or p95.</error>');

            return Command::INVALID;
        }
        $dwellBuckets = (int) $input->getOption('dwell-buckets');
        if ($dwellBuckets < 1) {
            $output->writeln('<error>Invalid --dwell-buckets. Use a positive integer.</error>');

            return Command::INVALID;
        }
        $flowBuckets = (int) $input->getOption('flow-buckets');
        if ($flowBuckets < 1) {
            $output->writeln('<error>Invalid --flow-buckets. Use a positive integer.</error>');

            return Command::INVALID;
        }

        $templatePath = (string) $input->getArgument('template');
        if (!is_file($templatePath)) {
            $output->writeln(sprintf('<error>Template file not found: %s</error>', $templatePath));

            return Command::FAILURE;
        }

        $templateData = Yaml::parseFile($templatePath);
        if (!is_array($templateData)) {
            $output->writeln(sprintf('<error>Template file is not a YAML mapping: %s</error>', $templatePath));

            return Command::FAILURE;
        }

        $template = ProcessTemplateArrayFactory::fromArray($templateData);
        $graph = $this->graphFactory->create($template);
        try {
            $metricsPath = $input->getOption('metrics') ?: $input->getOption('heatmap');
            $metricsDebug = null;
            if ($metricsPath !== null && $metricsPath !== '') {
                $metricsReport = $this->metricsReport($metricsPath);
            } elseif ($view === MermaidProcessGraphRenderOptions::VIEW_STRUCTURE) {
                $metricsReport = null;
            } else {
                [$metricsReport, $metricsDebug] = $this->liveMetricsReport($input, $template, $graph);
            }
        } catch (\InvalidArgumentException $exception) {
            $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));

            return Command::FAILURE;
        }
        $enrichedGraph = $this->metricsFactory->enrich($graph, $metricsReport, $template);
        $renderOptions = new MermaidProcessGraphRenderOptions(
            $input->getOption('show-default-order') === true,
            $compatibility,
            $view,
            $dwellMetric,
            MermaidProcessGraphRenderOptions::DWELL_SCALE_RELATIVE_PERCENTILE,
            $dwellBuckets,
            $flowBuckets,
            $input->getOption('show-node-metrics') === true
        );
        $diagram = $this->mermaidRenderer->render($enrichedGraph, $renderOptions);
        $dwellLegend = $input->getOption('show-dwell-legend') === true
            ? $this->dwellLegend($enrichedGraph->metrics, $renderOptions)
            : null;
        $flowLegend = $input->getOption('show-flow-legend') === true
            ? $this->flowLegend($enrichedGraph->metrics, $renderOptions)
            : null;

        $outputPath = $input->getOption('output');
        if ($outputPath !== null && $outputPath !== '') {
            file_put_contents((string) $outputPath, $diagram);
            if ($input->getOption('debug-metrics') === true && $metricsDebug !== null) {
                $output->writeln(json_encode($metricsDebug, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            }
            if ($dwellLegend !== null) {
                $output->writeln(json_encode($dwellLegend, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            }
            if ($flowLegend !== null) {
                $output->writeln(json_encode($flowLegend, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            }

            return Command::SUCCESS;
        }

        if ($input->getOption('debug-metrics') === true && $metricsDebug !== null) {
            $output->writeln(json_encode($metricsDebug, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
        if ($dwellLegend !== null) {
            $output->writeln(json_encode($dwellLegend, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
        if ($flowLegend !== null) {
            $output->writeln(json_encode($flowLegend, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
        $output->write($diagram);

        return Command::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function dwellLegend(\App\Intelligence\Domain\ProcessGraphMetrics $metrics, MermaidProcessGraphRenderOptions $options): array
    {
        $counts = [];
        foreach (MermaidProcessGraphRenderer::relativeDwellBuckets($options->dwellBuckets) as $bucket) {
            $counts[$bucket['class']] = 0;
        }

        foreach ($this->mermaidRenderer->dwellBucketClassesByNode($metrics, $options) as $class) {
            ++$counts[$class];
        }

        return [
            'dwell_metric' => $options->dwellMetric,
            'dwell_scale' => $options->dwellScale,
            'color_space' => 'yellow-red',
            'dwell_buckets' => $options->dwellBuckets,
            'lower_percentile' => 'p10',
            'upper_percentile' => 'p90',
            'p10_seconds' => $this->mermaidRenderer->dwellPercentile($metrics, $options, 10.0),
            'p90_seconds' => $this->mermaidRenderer->dwellPercentile($metrics, $options, 90.0),
            'semantics' => $options->view === MermaidProcessGraphRenderOptions::VIEW_COMBINED
                ? MermaidProcessGraphRenderer::combinedNote()
                : 'Node color = relative dwell time. Edges remain neutral in dwell view.',
            'note' => MermaidProcessGraphRenderer::dwellRelativeNote(),
            'no_dwell_note' => 'Neutral/hellgelb = keine belastbare Dwell-Messung oder virtueller Prozessknoten.',
            'dwell_legend' => array_map(
                static fn (array $bucket): array => [
                    'class' => $bucket['class'],
                    'bucket' => $bucket['bucket'],
                    'fill' => $bucket['fill'],
                    'node_count' => $counts[$bucket['class']] ?? 0,
                ],
                MermaidProcessGraphRenderer::relativeDwellBuckets($options->dwellBuckets)
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function flowLegend(\App\Intelligence\Domain\ProcessGraphMetrics $metrics, MermaidProcessGraphRenderOptions $options): array
    {
        $counts = [];
        foreach (MermaidProcessGraphRenderer::relativeFlowBuckets($options->flowBuckets) as $bucket) {
            $counts[$bucket['class']] = 0;
        }

        foreach ($this->mermaidRenderer->flowBucketClassesByNode($metrics, $options) as $class) {
            ++$counts[$class];
        }

        return [
            'flow_scale' => 'relative-percentile',
            'color_space' => 'yellow-red',
            'flow_buckets' => $options->flowBuckets,
            'lower_percentile' => 'p10',
            'upper_percentile' => 'p90',
            'p10_count' => $this->mermaidRenderer->flowPercentile($metrics, 10.0),
            'p90_count' => $this->mermaidRenderer->flowPercentile($metrics, 90.0),
            'semantics' => 'Node color = relative document volume. Edge width = relative edge volume.',
            'note' => MermaidProcessGraphRenderer::flowRelativeNote(),
            'flow_legend' => array_map(
                static fn (array $bucket): array => [
                    'class' => $bucket['class'],
                    'bucket' => $bucket['bucket'],
                    'fill' => $bucket['fill'],
                    'node_count' => $counts[$bucket['class']] ?? 0,
                ],
                MermaidProcessGraphRenderer::relativeFlowBuckets($options->flowBuckets)
            ),
        ];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function liveMetricsReport(InputInterface $input, \App\Intelligence\Domain\ProcessTemplate $template, \App\Intelligence\Domain\ProcessGraph $graph): array
    {
        if ($this->heatmapReportBuilder === null || $this->documentUuidProvider === null || $this->timelineProvider === null || $this->timelineFilter === null) {
            throw new \InvalidArgumentException('Live metrics require TemplateHeatmapReportBuilder, ProcessDocumentUuidProvider, DocumentTimelineProvider and KpiRelevantTimelineFilter services.');
        }

        $processKey = (string) ($input->getOption('process-key') ?: $template->key);
        $order = EventTimelineOrder::fromOption((string) $input->getOption('order-by'));
        if ($order === null) {
            throw new \InvalidArgumentException(sprintf('Invalid --order-by. Use one of: %s.', implode(', ', EventTimelineOrder::values())));
        }

        $from = $this->dateOption($input->getOption('from'), '--from');
        $to = $this->dateOption($input->getOption('to'), '--to');
        $versionOption = $input->getOption('document-version');
        $documentVersion = $versionOption === null ? null : (int) $versionOption;
        $documentRefs = $this->documentUuidProvider->documentRefsForProcess($processKey, $from);
        $documentsForMetrics = [];
        $debugDocuments = [];
        $skipReasons = [];
        $rawTransitionCount = 0;
        $projectedEdgeCount = 0;
        $unexpectedEdgeCount = 0;

        foreach ($documentRefs as $documentRef) {
            if (!$this->documentMatchesOptions($documentRef, $input)) {
                $skipReasons['filtered'][] = $documentRef->documentExternalId ?? $documentRef->documentUuid;
                continue;
            }

            $events = $this->timelineEvents($documentRef, $processKey, $documentVersion, $order, $from, $to);
            if ($events === []) {
                $skipReasons['no_timeline_events'][] = $documentRef->documentExternalId ?? $documentRef->documentUuid;
                continue;
            }

            if (!$this->documentStatusIncluded($documentRef, $processKey, $template, $documentVersion, $order, $input)) {
                $skipReasons['status_filter'][] = $documentRef->documentExternalId ?? $documentRef->documentUuid;
                continue;
            }

            $timeline = array_map(
                static fn (DocumentTimelineEventRow $event): array => [
                    'step' => $event->stepKey,
                    'occurred_at' => $event->occurredAt->format(DATE_ATOM),
                ],
                $events
            );
            $documentForMetrics = [
                'document_uuid' => $documentRef->documentUuid,
                'document_id' => $documentRef->documentExternalId,
                'timeline' => $timeline,
                'events' => $events,
            ];

            $debugTransitions = [];
            for ($index = 0, $max = count($events) - 1; $index < $max; ++$index) {
                ++$rawTransitionCount;
                $projection = $this->observationProjector->project(
                    $graph,
                    $template,
                    $events[$index]->stepKey,
                    $events[$index + 1]->stepKey,
                    $this->contextFromEvent($events[$index])
                );
                $projectedEdgeCount += count($projection->projectedEdges);
                if ($projection->isUnexpected()) {
                    ++$unexpectedEdgeCount;
                }
                $debugTransitions[] = [
                    'from' => $events[$index]->stepKey,
                    'to' => $events[$index + 1]->stepKey,
                    'classification' => $projection->classification,
                    'projected_edges' => array_map(
                        static fn (array $edge): string => $edge[0].' -> '.$edge[1],
                        $projection->projectedEdges
                    ),
                ];
            }

            $documentForMetrics['debug'] = [
                'documentId' => $documentRef->documentExternalId,
                'documentUuid' => $documentRef->documentUuid,
                'transitions' => $debugTransitions,
            ];
            $documentsForMetrics[] = $documentForMetrics;
        }

        $filterResult = $this->timelineFilter->filterDocumentTimelines(
            $template,
            $processKey,
            $documentsForMetrics,
            $input->getOption('include-excluded') === true
        );
        $documentTimelines = array_map(
            static fn (array $document): array => [
                'document_uuid' => $document['document_uuid'],
                'document_id' => $document['document_id'],
                'timeline' => $document['timeline'],
            ],
            $filterResult->included
        );
        $documentTimelineEvents = array_map(
            static fn (array $document): array => $document['events'],
            $filterResult->included
        );
        $debugDocuments = array_map(
            static fn (array $document): array => $document['debug'],
            $filterResult->included
        );
        $report = $this->heatmapReportBuilder->build($template, $documentTimelines, new DateTimeImmutable(), true);
        $report['kpi_eligibility'] = $filterResult->summary;
        if ($input->getOption('include-excluded') === true) {
            $report['kpi_eligibility']['excluded_timelines'] = $filterResult->excluded;
        }
        $report['flow_heatmap'] = $this->flowHeatmapFromEvents($documentTimelineEvents);
        $report['virtual_node_durations'] = $this->virtualNodeDurationsFromEvents($documentTimelineEvents, $template, $graph);
        $report['node_flow'] = $this->nodeFlowFromEvents($documentTimelineEvents, $template, $graph);
        $debug = [
            'source' => 'live_process_timeline',
            'process_key' => $processKey,
            'documents_seen' => count($documentRefs),
            'documents_projected' => count($documentTimelines),
            'documents_skipped' => array_sum(array_map('count', $skipReasons))
                + ($input->getOption('include-excluded') === true ? 0 : $filterResult->summary['excluded_instances']),
            'skip_reasons' => $skipReasons,
            'kpi_eligibility' => $report['kpi_eligibility'],
            'raw_transition_count' => $rawTransitionCount,
            'projected_edge_count' => $projectedEdgeCount,
            'unexpected_edge_count' => $unexpectedEdgeCount,
            'node_flow_count' => count($report['node_flow']['nodes'] ?? []),
            'virtual_node_duration_count' => array_sum(array_map(
                static fn (array $node): int => count($node['durations_seconds'] ?? []),
                $report['virtual_node_durations']['nodes'] ?? []
            )),
            'documents' => $debugDocuments,
        ];

        return [$report, $debug];
    }

    /**
     * @param array<int, array<int, DocumentTimelineEventRow>> $documentTimelineEvents
     * @return array<string, mixed>
     */
    private function flowHeatmapFromEvents(array $documentTimelineEvents): array
    {
        $transitionCounts = [];
        $documentsUsed = 0;
        foreach ($documentTimelineEvents as $events) {
            if ($events === []) {
                continue;
            }

            ++$documentsUsed;
            for ($index = 0, $max = count($events) - 1; $index < $max; ++$index) {
                $context = $this->contextFromEvent($events[$index]);
                $key = implode("\0", [
                    $events[$index]->stepKey,
                    $events[$index + 1]->stepKey,
                    json_encode($context, JSON_THROW_ON_ERROR),
                ]);
                $transitionCounts[$key] ??= [
                    'from' => $events[$index]->stepKey,
                    'to' => $events[$index + 1]->stepKey,
                    'context' => $context,
                    'count' => 0,
                ];
                ++$transitionCounts[$key]['count'];
            }
        }

        $maxCount = $transitionCounts === [] ? 0 : max(array_map(
            static fn (array $transition): int => $transition['count'],
            $transitionCounts
        ));
        $transitions = array_values(array_map(
            static fn (array $transition): array => [
                'from' => $transition['from'],
                'to' => $transition['to'],
                'context' => $transition['context'],
                'count' => $transition['count'],
                'percentage' => $documentsUsed === 0 ? 0.0 : round($transition['count'] / $documentsUsed * 100, 2),
                'intensity' => $maxCount === 0 ? 0.0 : round($transition['count'] / $maxCount, 4),
            ],
            $transitionCounts
        ));
        usort(
            $transitions,
            static fn (array $left, array $right): int => ($right['count'] <=> $left['count'])
                ?: ($left['from'] <=> $right['from'])
                ?: ($left['to'] <=> $right['to'])
                ?: (json_encode($left['context'], JSON_THROW_ON_ERROR) <=> json_encode($right['context'], JSON_THROW_ON_ERROR))
        );

        return ['transitions' => $transitions];
    }

    /**
     * @param array<int, array<int, DocumentTimelineEventRow>> $documentTimelineEvents
     * @return array<string, mixed>
     */
    private function nodeFlowFromEvents(array $documentTimelineEvents, \App\Intelligence\Domain\ProcessTemplate $template, \App\Intelligence\Domain\ProcessGraph $graph): array
    {
        $documentsByNode = [];
        foreach ($documentTimelineEvents as $documentIndex => $events) {
            if ($events === []) {
                continue;
            }

            $documentKey = 'document-'.$documentIndex;
            foreach ($events as $event) {
                $this->markNodeFlow($documentsByNode, $event->stepKey, $graph->nodes[$event->stepKey]->type ?? 'task', $documentKey);
            }

            foreach ($template->parallelGroups as $group) {
                $seenRequired = [];
                foreach ($events as $event) {
                    if (in_array($event->stepKey, $group->requiredStepKeys, true)) {
                        $seenRequired[$event->stepKey] = true;
                    }
                }
                if (count(array_intersect($group->requiredStepKeys, array_keys($seenRequired))) === count($group->requiredStepKeys)) {
                    $joinNode = 'parallel_join:'.$group->key;
                    if (isset($graph->nodes[$joinNode])) {
                        $this->markNodeFlow($documentsByNode, $joinNode, $graph->nodes[$joinNode]->type, $documentKey);
                    }
                }
            }

            for ($index = 0, $max = count($events) - 1; $index < $max; ++$index) {
                $projection = $this->observationProjector->project(
                    $graph,
                    $template,
                    $events[$index]->stepKey,
                    $events[$index + 1]->stepKey,
                    $this->contextFromEvent($events[$index])
                );
                foreach ($projection->projectedEdges as [$from, $to]) {
                    foreach ([$from, $to] as $nodeKey) {
                        if ($nodeKey === $events[$index]->stepKey || $nodeKey === $events[$index + 1]->stepKey || !isset($graph->nodes[$nodeKey])) {
                            continue;
                        }
                        $this->markNodeFlow($documentsByNode, $nodeKey, $graph->nodes[$nodeKey]->type, $documentKey);
                    }
                }
            }
        }

        ksort($documentsByNode);
        $nodes = [];
        foreach ($documentsByNode as $nodeKey => $node) {
            $nodes[] = [
                'node_key' => $nodeKey,
                'node_type' => $node['node_type'],
                'count' => count($node['documents']),
            ];
        }

        return ['nodes' => $nodes];
    }

    /**
     * @param array<string, array{node_type: string, documents: array<string, true>}> $documentsByNode
     */
    private function markNodeFlow(array &$documentsByNode, string $nodeKey, string $nodeType, string $documentKey): void
    {
        if ($nodeKey === '__start' || $nodeKey === '__end') {
            return;
        }

        $documentsByNode[$nodeKey] ??= [
            'node_type' => $nodeType,
            'documents' => [],
        ];
        $documentsByNode[$nodeKey]['documents'][$documentKey] = true;
    }

    /**
     * @param array<int, array<int, DocumentTimelineEventRow>> $documentTimelineEvents
     * @return array<string, mixed>
     */
    private function virtualNodeDurationsFromEvents(array $documentTimelineEvents, \App\Intelligence\Domain\ProcessTemplate $template, \App\Intelligence\Domain\ProcessGraph $graph): array
    {
        $durationsByNode = [];
        foreach ($documentTimelineEvents as $events) {
            for ($index = 0, $max = count($events) - 1; $index < $max; ++$index) {
                $projection = $this->observationProjector->project(
                    $graph,
                    $template,
                    $events[$index]->stepKey,
                    $events[$index + 1]->stepKey,
                    $this->contextFromEvent($events[$index])
                );
                if ($projection->classification !== ObservedTransitionProjection::EXPECTED_VIA_DECISION) {
                    continue;
                }

                $decisionNode = $this->firstProjectedNodeWithPrefix($projection->projectedEdges, 'decision:');
                if ($decisionNode === null) {
                    continue;
                }

                $this->addVirtualNodeDuration(
                    $durationsByNode,
                    $decisionNode,
                    $graph->nodes[$decisionNode]->type ?? 'decision',
                    $this->durationSeconds($events[$index]->occurredAt, $events[$index + 1]->occurredAt)
                );
            }

            foreach ($this->parallelGroupDurationsForEvents($events, $template, $graph) as $nodeKey => $node) {
                foreach ($node['durations_seconds'] as $durationSeconds) {
                    $this->addVirtualNodeDuration(
                        $durationsByNode,
                        $nodeKey,
                        $node['node_type'],
                        $durationSeconds
                    );
                }
            }
        }

        ksort($durationsByNode);

        return ['nodes' => array_values($durationsByNode)];
    }

    /**
     * @param array<int, DocumentTimelineEventRow> $events
     * @return array<string, array{node_key: string, node_type: string, durations_seconds: list<float>}>
     */
    private function parallelGroupDurationsForEvents(array $events, \App\Intelligence\Domain\ProcessTemplate $template, \App\Intelligence\Domain\ProcessGraph $graph): array
    {
        $durationsByNode = [];
        foreach ($template->parallelGroups as $group) {
            $startNode = $group->nextStepKey === null ? 'parallel:'.$group->key : 'parallel_start:'.$group->key;
            $joinNode = 'parallel_join:'.$group->key;
            $startedAt = null;
            $seenRequiredSteps = [];
            $completed = false;

            foreach ($events as $index => $event) {
                if ($index < count($events) - 1) {
                    $projection = $this->observationProjector->project(
                        $graph,
                        $template,
                        $event->stepKey,
                        $events[$index + 1]->stepKey,
                        $this->contextFromEvent($event)
                    );
                    if ($this->hasProjectedEdgeTo($projection->projectedEdges, $startNode)) {
                        $startedAt ??= $event->occurredAt;
                    }
                }

                if (in_array($event->stepKey, $group->requiredStepKeys, true)) {
                    $startedAt ??= $event->occurredAt;
                    $seenRequiredSteps[$event->stepKey] = true;
                }

                if ($completed || $startedAt === null) {
                    continue;
                }

                if (count(array_intersect($group->requiredStepKeys, array_keys($seenRequiredSteps))) !== count($group->requiredStepKeys)) {
                    continue;
                }

                $durationSeconds = $this->durationSeconds($startedAt, $event->occurredAt);
                $this->addVirtualNodeDuration($durationsByNode, $joinNode, $graph->nodes[$joinNode]->type ?? 'parallel_join', $durationSeconds);
                if (isset($graph->nodes[$startNode])) {
                    $this->addVirtualNodeDuration($durationsByNode, $startNode, $graph->nodes[$startNode]->type, $durationSeconds);
                }
                $completed = true;
            }
        }

        return $durationsByNode;
    }

    /**
     * @param array<int, array{0: string, 1: string}> $projectedEdges
     */
    private function firstProjectedNodeWithPrefix(array $projectedEdges, string $prefix): ?string
    {
        foreach ($projectedEdges as [$from, $to]) {
            if (str_starts_with($from, $prefix)) {
                return $from;
            }
            if (str_starts_with($to, $prefix)) {
                return $to;
            }
        }

        return null;
    }

    /**
     * @param array<int, array{0: string, 1: string}> $projectedEdges
     */
    private function hasProjectedEdgeTo(array $projectedEdges, string $nodeKey): bool
    {
        foreach ($projectedEdges as [, $to]) {
            if ($to === $nodeKey) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, array{node_key: string, node_type: string, durations_seconds: list<float>}> $durationsByNode
     */
    private function addVirtualNodeDuration(array &$durationsByNode, string $nodeKey, string $nodeType, float $durationSeconds): void
    {
        $durationsByNode[$nodeKey] ??= [
            'node_key' => $nodeKey,
            'node_type' => $nodeType,
            'durations_seconds' => [],
        ];
        $durationsByNode[$nodeKey]['durations_seconds'][] = max(0.0, $durationSeconds);
    }

    private function durationSeconds(DateTimeImmutable $from, DateTimeImmutable $to): float
    {
        return max(0.0, (float) ($to->getTimestamp() - $from->getTimestamp()));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function contextFromEvent(DocumentTimelineEventRow $event): ?array
    {
        $attributes = $event->contextSummary['attributes'] ?? null;

        return is_array($attributes) ? $attributes : null;
    }

    /**
     * @return array<int, DocumentTimelineEventRow>
     */
    private function timelineEvents(
        ProcessDocumentRef $documentRef,
        string $processKey,
        ?int $documentVersion,
        EventTimelineOrder $order,
        ?DateTimeImmutable $from,
        ?DateTimeImmutable $to
    ): array {
        $events = array_values(array_filter(
            $this->timelineProvider?->build($documentRef->documentUuid, $order)->events ?? [],
            static fn (DocumentTimelineEventRow $event): bool => $event->processKey === $processKey
                && $event->eventPhase === 'after'
                && ($documentVersion === null || $event->documentVersion === $documentVersion)
                && ($from === null || $event->occurredAt >= $from || $event->receivedAt >= $from)
                && ($to === null || $event->occurredAt <= $to)
        ));
        usort($events, static fn (DocumentTimelineEventRow $left, DocumentTimelineEventRow $right): int => $order->compareTimelineRows($left, $right));

        return $events;
    }

    private function documentMatchesOptions(ProcessDocumentRef $documentRef, InputInterface $input): bool
    {
        $documentId = $input->getOption('document-id');
        if ($documentId !== null && $documentId !== '' && !in_array((string) $documentId, [$documentRef->documentExternalId, $documentRef->documentUuid], true)) {
            return false;
        }

        if ($input->getOption('sample-only') !== true) {
            return true;
        }

        return $documentRef->documentExternalId !== null
            && preg_match('/^(90000[1-8]|9010(0[1-9]|1[0-2]))$/', $documentRef->documentExternalId) === 1;
    }

    private function documentStatusIncluded(
        ProcessDocumentRef $documentRef,
        string $processKey,
        \App\Intelligence\Domain\ProcessTemplate $template,
        ?int $documentVersion,
        EventTimelineOrder $order,
        InputInterface $input
    ): bool {
        $includeOk = $input->getOption('include-ok') === true;
        $includeDeviations = $input->getOption('include-deviations') === true;
        if (!$includeOk && !$includeDeviations) {
            return true;
        }
        if ($this->checkService === null) {
            return true;
        }

        $result = $this->checkService->checkDocument($documentRef->documentUuid, $processKey, $template, $documentVersion, $order);
        $hasDeviation = !$result->isOk();

        return ($includeDeviations && $hasDeviation) || ($includeOk && !$hasDeviation);
    }

    private function dateOption(mixed $value, string $name): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable((string) $value);
        } catch (\Exception) {
            throw new \InvalidArgumentException(sprintf('Invalid %s datetime: %s', $name, (string) $value));
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function metricsReport(mixed $path): ?array
    {
        if ($path === null || $path === '') {
            return null;
        }

        $path = (string) $path;
        if (!is_file($path)) {
            throw new \InvalidArgumentException(sprintf('Metrics file not found: %s', $path));
        }

        $content = (string) file_get_contents($path);
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $parsed = Yaml::parse($content);

        return is_array($parsed) ? $parsed : null;
    }
}
