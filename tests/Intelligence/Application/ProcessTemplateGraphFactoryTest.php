<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\MermaidProcessGraphRenderer;
use App\Intelligence\Application\MermaidProcessGraphRenderOptions;
use App\Intelligence\Application\ProcessGraphMetricsFactory;
use App\Intelligence\Application\ProcessTemplateGraphFactory;
use App\Intelligence\Domain\EnrichedProcessGraph;
use App\Intelligence\Domain\ProcessGraph;
use App\Intelligence\Domain\ProcessGraphEdge;
use App\Intelligence\Domain\ProcessGraphEdgeMetrics;
use App\Intelligence\Domain\ProcessGraphMetrics;
use App\Intelligence\Domain\ProcessGraphNode;
use App\Intelligence\Domain\ProcessGraphNodeMetrics;
use App\Intelligence\Domain\ProcessTemplateArrayFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ProcessTemplateGraphFactoryTest extends TestCase
{
    public function testCreatesNeutralGraphFromTemplate(): void
    {
        $graph = (new ProcessTemplateGraphFactory())->create($this->template());

        self::assertSame('ai-rechnungen', $graph->key);
        self::assertSame(ProcessGraphNode::TYPE_START, $graph->nodes['__start']->type);
        self::assertSame(ProcessGraphNode::TYPE_END, $graph->nodes['__end']->type);
        self::assertSame(ProcessGraphNode::TYPE_TASK, $graph->nodes['01 Rechnungen pruefen']->type);
        self::assertTrue($graph->nodes['01 Rechnungen pruefen']->required);
        self::assertSame(ProcessGraphNode::TYPE_EXCLUSIVE_GATEWAY, $graph->nodes['decision:route_after_pruefung']->type);
        self::assertArrayNotHasKey('parallel:buchen_und_zahlung', $graph->nodes);
        self::assertSame(ProcessGraphNode::TYPE_PARALLEL_START, $graph->nodes['parallel_start:buchen_und_zahlung']->type);
        self::assertSame(ProcessGraphNode::TYPE_PARALLEL_JOIN, $graph->nodes['parallel_join:buchen_und_zahlung']->type);
        self::assertSame(
            "buchen_und_zahlung\nstart\norder:any",
            $graph->nodes['parallel_start:buchen_und_zahlung']->label
        );
        self::assertSame(
            "buchen_und_zahlung\ncomplete",
            $graph->nodes['parallel_join:buchen_und_zahlung']->label
        );

        self::assertTrue($this->hasEdge($graph->edges, 'decision:route_after_pruefung', '02 Versenden', '[1] invoice_direction eq RE - Ausgang'));
        self::assertTrue($this->hasEdge($graph->edges, 'decision:route_after_pruefung', '03 Freigabe_klein', '[2] amount_net gt 50'));
        self::assertTrue($this->hasEdge($graph->edges, 'decision:route_after_pruefung', 'parallel_start:buchen_und_zahlung', '[else]'));
        self::assertFalse($this->hasEdge($graph->edges, 'decision:route_after_pruefung', '05 Ausgangsrechnung buchen', '[else]'));
        self::assertFalse($this->hasEdge($graph->edges, 'decision:route_after_pruefung', 'parallel_join:buchen_und_zahlung', '[else]'));
        self::assertTrue($this->hasEdge($graph->edges, 'parallel_start:buchen_und_zahlung', '05 Ausgangsrechnung buchen', null));
        self::assertTrue($this->hasEdge($graph->edges, 'parallel_start:buchen_und_zahlung', '07 Zahlungseingang erwartet', null));
        self::assertTrue($this->hasEdge($graph->edges, '05 Ausgangsrechnung buchen', 'parallel_join:buchen_und_zahlung', null));
        self::assertTrue($this->hasEdge($graph->edges, '07 Zahlungseingang erwartet', 'parallel_join:buchen_und_zahlung', null));
        self::assertTrue($this->hasEdge($graph->edges, 'parallel_join:buchen_und_zahlung', '09 Rechnungen Abschluss', null));
        self::assertFalse($this->hasEdge($graph->edges, '02 Versenden', '05 Ausgangsrechnung buchen', null));
        self::assertTrue($this->hasEdge($graph->edges, '02 Versenden', 'parallel_start:buchen_und_zahlung', null));
        self::assertTrue($this->hasEdge($graph->edges, '04 Freigabe_gross', 'parallel_start:buchen_und_zahlung', null));
        self::assertFalse($this->hasEdge($graph->edges, '02 Versenden', 'parallel_join:buchen_und_zahlung', null));
        self::assertFalse($this->hasEdge($graph->edges, '04 Freigabe_gross', 'parallel_join:buchen_und_zahlung', null));
        self::assertFalse($this->hasEdge($graph->edges, 'parallel:buchen_und_zahlung', '05 Ausgangsrechnung buchen', 'any'));
        self::assertFalse($this->hasEdge($graph->edges, '02 Versenden', '03 Freigabe_klein', 'default order', ProcessGraphEdge::STYLE_IMPLICIT));
        self::assertFalse($this->hasEdge($graph->edges, '07 Zahlungseingang erwartet', '09 Rechnungen Abschluss', 'default order', ProcessGraphEdge::STYLE_IMPLICIT));
    }

    public function testMermaidRendererHidesDefaultOrderEdgesByDefault(): void
    {
        $graph = (new ProcessTemplateGraphFactory())->create($this->template());

        $mermaid = (new MermaidProcessGraphRenderer())->render($graph);

        self::assertStringStartsWith("flowchart TD\n", $mermaid);
        self::assertStringContainsString('n_01_Rechnungen_pruefen["01 Rechnungen pruefen"]:::required', $mermaid);
        self::assertStringContainsString('n_decision_route_after_pruefung{route_after_pruefung}', $mermaid);
        self::assertStringContainsString('n_parallel_start_buchen_und_zahlung{{buchen_und_zahlung<br/>start<br/>order:any}}:::constraint', $mermaid);
        self::assertStringContainsString('n_parallel_join_buchen_und_zahlung{{buchen_und_zahlung<br/>complete}}:::constraint', $mermaid);
        self::assertStringContainsString('n_decision_route_after_pruefung -->|"[1] invoice_direction eq RE - Ausgang"| n_02_Versenden', $mermaid);
        self::assertStringContainsString('n_decision_route_after_pruefung -->|"[2] amount_net gt 50"| n_03_Freigabe_klein', $mermaid);
        self::assertStringContainsString('n_decision_route_after_pruefung -->|"[else]"| n_parallel_start_buchen_und_zahlung', $mermaid);
        self::assertStringNotContainsString('n_decision_route_after_pruefung -->|"[else]"| n_05_Ausgangsrechnung_buchen', $mermaid);
        self::assertStringNotContainsString('n_decision_route_after_pruefung -->|"[else]"| n_parallel_join_buchen_und_zahlung', $mermaid);
        self::assertStringContainsString('n_parallel_start_buchen_und_zahlung --> n_05_Ausgangsrechnung_buchen', $mermaid);
        self::assertStringContainsString('n_parallel_start_buchen_und_zahlung --> n_07_Zahlungseingang_erwartet', $mermaid);
        self::assertStringContainsString('n_05_Ausgangsrechnung_buchen --> n_parallel_join_buchen_und_zahlung', $mermaid);
        self::assertStringContainsString('n_07_Zahlungseingang_erwartet --> n_parallel_join_buchen_und_zahlung', $mermaid);
        self::assertStringContainsString('n_parallel_join_buchen_und_zahlung --> n_09_Rechnungen_Abschluss', $mermaid);
        self::assertStringNotContainsString('n_02_Versenden --> n_05_Ausgangsrechnung_buchen', $mermaid);
        self::assertStringContainsString('n_02_Versenden --> n_parallel_start_buchen_und_zahlung', $mermaid);
        self::assertStringContainsString('n_04_Freigabe_gross --> n_parallel_start_buchen_und_zahlung', $mermaid);
        self::assertStringNotContainsString('n_02_Versenden --> n_parallel_join_buchen_und_zahlung', $mermaid);
        self::assertStringNotContainsString('n_04_Freigabe_gross --> n_parallel_join_buchen_und_zahlung', $mermaid);
        self::assertStringNotContainsString('n_parallel_buchen_und_zahlung -->|any| n_05_Ausgangsrechnung_buchen', $mermaid);
        self::assertStringNotContainsString('default order', $mermaid);
        self::assertStringContainsString('classDef required', $mermaid);
        self::assertStringContainsString('classDef constraint', $mermaid);
        self::assertStringContainsString('classDef implicit', $mermaid);
    }

    public function testMermaidRendererShowsDefaultOrderEdgesWhenEnabled(): void
    {
        $template = ProcessTemplateArrayFactory::fromArray([
            'key' => 'invoice',
            'steps' => [
                ['key' => 'sent'],
                ['key' => 'booked'],
            ],
        ]);
        $graph = (new ProcessTemplateGraphFactory())->create($template);

        $mermaid = (new MermaidProcessGraphRenderer())->render($graph, true);

        self::assertStringContainsString('n_sent -.->|"default order"| n_booked', $mermaid);
    }

    public function testMermaidRendererEscapesSpecialCharactersInEdgeLabels(): void
    {
        $graph = new ProcessGraph(
            'test',
            'draft',
            [
                'a' => new ProcessGraphNode('a', 'A', ProcessGraphNode::TYPE_TASK),
                'b' => new ProcessGraphNode('b', 'B', ProcessGraphNode::TYPE_TASK),
            ],
            [
                new ProcessGraphEdge('a', 'b', "[1] \"quoted\"\nleft|right"),
            ]
        );

        $mermaid = (new MermaidProcessGraphRenderer())->render($graph);

        self::assertStringContainsString('n_a -->|"[1] &quot;quoted&quot;<br/>left/right"| n_b', $mermaid);
    }

    public function testMermaidRendererUsesObsidianCompatiblePriorityLabels(): void
    {
        $graph = (new ProcessTemplateGraphFactory())->create($this->template());

        $mermaid = (new MermaidProcessGraphRenderer())->render(
            $graph,
            new MermaidProcessGraphRenderOptions(compatibility: MermaidProcessGraphRenderOptions::COMPAT_OBSIDIAN)
        );

        self::assertStringContainsString('n_decision_route_after_pruefung -->|"(1) invoice_direction eq RE - Ausgang"| n_02_Versenden', $mermaid);
        self::assertStringContainsString('n_decision_route_after_pruefung -->|"(2) amount_net gt 50"| n_03_Freigabe_klein', $mermaid);
        self::assertStringContainsString('n_decision_route_after_pruefung -->|"(else)"| n_parallel_start_buchen_und_zahlung', $mermaid);
        self::assertStringNotContainsString('-->|"[1]', $mermaid);
    }

    public function testMermaidFlowViewRendersCountLabels(): void
    {
        $graph = new ProcessGraph(
            'test',
            'draft',
            [
                'a' => new ProcessGraphNode('a', 'A', ProcessGraphNode::TYPE_TASK),
                'b' => new ProcessGraphNode('b', 'B', ProcessGraphNode::TYPE_TASK),
            ],
            [
                new ProcessGraphEdge('a', 'b'),
            ]
        );
        $enrichedGraph = new EnrichedProcessGraph(
            $graph,
            new ProcessGraphMetrics(edges: [
                ProcessGraphMetrics::edgeKey('a', 'b') => new ProcessGraphEdgeMetrics('a', 'b', observedCount: 3),
            ])
        );

        $mermaid = (new MermaidProcessGraphRenderer())->render(
            $enrichedGraph,
            new MermaidProcessGraphRenderOptions(view: MermaidProcessGraphRenderOptions::VIEW_FLOW)
        );

        self::assertStringContainsString('n_a -->|"count 3"| n_b', $mermaid);
        self::assertStringContainsString('linkStyle 0 stroke-width:4px;', $mermaid);
    }

    public function testMermaidDwellViewRendersDwellClasses(): void
    {
        $graph = new ProcessGraph(
            'test',
            'draft',
            [
                'slow' => new ProcessGraphNode('slow', 'Slow', ProcessGraphNode::TYPE_TASK),
            ],
            []
        );
        $enrichedGraph = new EnrichedProcessGraph(
            $graph,
            new ProcessGraphMetrics(nodes: [
                'slow' => new ProcessGraphNodeMetrics(observedCount: 2, medianDwellSeconds: 7200),
            ])
        );

        $mermaid = (new MermaidProcessGraphRenderer())->render(
            $enrichedGraph,
            new MermaidProcessGraphRenderOptions(view: MermaidProcessGraphRenderOptions::VIEW_DWELL)
        );

        self::assertStringContainsString('n_slow["Slow"]:::dwell-scale-3', $mermaid);
        self::assertStringContainsString('classDef dwell-scale-3', $mermaid);
        self::assertStringContainsString('Node color = relative dwell time. Dwell colors use a relative yellow-to-red percentile scale. Red means longest dwell time in the current dataset, not automatically critical.', $mermaid);
    }

    public function testMermaidDwellBucketsUseRelativePercentileScale(): void
    {
        $nodes = [];
        $metrics = [];
        foreach ([
            'below_p10' => 0,
            'p10ish' => 100,
            'middle' => 500,
            'above_p90' => 900,
            'missing' => null,
        ] as $nodeId => $seconds) {
            $nodes[$nodeId] = new ProcessGraphNode($nodeId, $nodeId, ProcessGraphNode::TYPE_TASK);
            if ($seconds !== null) {
                $metrics[$nodeId] = new ProcessGraphNodeMetrics(observedCount: 1, medianDwellSeconds: $seconds);
            }
        }
        foreach ([200, 300, 400, 600, 700, 800] as $seconds) {
            $nodeId = 'value_'.$seconds;
            $nodes[$nodeId] = new ProcessGraphNode($nodeId, $nodeId, ProcessGraphNode::TYPE_TASK);
            $metrics[$nodeId] = new ProcessGraphNodeMetrics(observedCount: 1, medianDwellSeconds: $seconds);
        }

        $mermaid = (new MermaidProcessGraphRenderer())->render(
            new EnrichedProcessGraph(
                new ProcessGraph('test', 'draft', $nodes, []),
                new ProcessGraphMetrics(nodes: $metrics)
            ),
            new MermaidProcessGraphRenderOptions(view: MermaidProcessGraphRenderOptions::VIEW_DWELL)
        );

        self::assertStringContainsString('n_below_p10["below_p10"]:::dwell-scale-0', $mermaid);
        self::assertStringContainsString('n_above_p90["above_p90"]:::dwell-scale-7', $mermaid);
        self::assertStringContainsString('n_middle["middle"]:::dwell-scale-3', $mermaid);
        self::assertStringContainsString('classDef dwell-scale-0 fill:#fefce8;', $mermaid);
        self::assertStringContainsString('classDef dwell-scale-7 fill:#fee2e2;', $mermaid);
        self::assertStringNotContainsString('classDef dwell-scale-7 fill:#fee2e2,stroke:', $mermaid);
        self::assertStringContainsString('n_missing["missing"]:::no-dwell', $mermaid);
        self::assertStringContainsString('classDef no-dwell fill:#fefce8,stroke:#a8a29e;', $mermaid);
    }

    public function testMermaidDwellViewUsesNeutralClassForNodesWithoutReliableDwell(): void
    {
        $graph = new ProcessGraph(
            'test',
            'draft',
            [
                'required_start' => new ProcessGraphNode('required_start', 'Required Start', ProcessGraphNode::TYPE_TASK, required: true),
                'measured' => new ProcessGraphNode('measured', 'Measured', ProcessGraphNode::TYPE_TASK),
                'end_step' => new ProcessGraphNode('end_step', 'End Step', ProcessGraphNode::TYPE_TASK, required: true),
            ],
            []
        );
        $enrichedGraph = new EnrichedProcessGraph(
            $graph,
            new ProcessGraphMetrics(nodes: [
                'required_start' => new ProcessGraphNodeMetrics(observedCount: 1, medianDwellSeconds: 0, reliableDwellCount: 0),
                'measured' => new ProcessGraphNodeMetrics(observedCount: 1, medianDwellSeconds: 600, reliableDwellCount: 1),
                'end_step' => new ProcessGraphNodeMetrics(observedCount: 1, medianDwellSeconds: 0, reliableDwellCount: 0),
            ])
        );

        $mermaid = (new MermaidProcessGraphRenderer())->render(
            $enrichedGraph,
            new MermaidProcessGraphRenderOptions(view: MermaidProcessGraphRenderOptions::VIEW_DWELL)
        );

        self::assertStringContainsString('n_required_start["Required Start"]:::required,no-dwell', $mermaid);
        self::assertStringContainsString('n_end_step["End Step"]:::required,no-dwell', $mermaid);
        self::assertStringContainsString('n_measured["Measured"]:::dwell-scale-3', $mermaid);
    }

    public function testMermaidDwellBucketsCanUseAverageOrP95Metric(): void
    {
        $graph = new ProcessGraph(
            'test',
            'draft',
            [
                'task' => new ProcessGraphNode('task', 'Task', ProcessGraphNode::TYPE_TASK),
                'reference' => new ProcessGraphNode('reference', 'Reference', ProcessGraphNode::TYPE_TASK),
            ],
            []
        );
        $enrichedGraph = new EnrichedProcessGraph(
            $graph,
            new ProcessGraphMetrics(nodes: [
                'task' => new ProcessGraphNodeMetrics(
                    observedCount: 1,
                    avgDwellSeconds: 600,
                    medianDwellSeconds: 120,
                    p95DwellSeconds: 40000
                ),
                'reference' => new ProcessGraphNodeMetrics(
                    observedCount: 1,
                    avgDwellSeconds: 40000,
                    medianDwellSeconds: 120,
                    p95DwellSeconds: 120
                ),
            ])
        );

        $avgMermaid = (new MermaidProcessGraphRenderer())->render(
            $enrichedGraph,
            new MermaidProcessGraphRenderOptions(view: MermaidProcessGraphRenderOptions::VIEW_DWELL, dwellMetric: MermaidProcessGraphRenderOptions::DWELL_METRIC_AVG)
        );
        $p95Mermaid = (new MermaidProcessGraphRenderer())->render(
            $enrichedGraph,
            new MermaidProcessGraphRenderOptions(view: MermaidProcessGraphRenderOptions::VIEW_DWELL, dwellMetric: MermaidProcessGraphRenderOptions::DWELL_METRIC_P95)
        );

        self::assertStringContainsString('n_task["Task"]:::dwell-scale-0', $avgMermaid);
        self::assertStringContainsString('n_task["Task"]:::dwell-scale-7', $p95Mermaid);
    }

    public function testMermaidDwellBucketsHandleEqualPercentiles(): void
    {
        $graph = new ProcessGraph(
            'test',
            'draft',
            [
                'a' => new ProcessGraphNode('a', 'A', ProcessGraphNode::TYPE_TASK),
                'b' => new ProcessGraphNode('b', 'B', ProcessGraphNode::TYPE_TASK),
            ],
            []
        );

        $mermaid = (new MermaidProcessGraphRenderer())->render(
            new EnrichedProcessGraph(
                $graph,
                new ProcessGraphMetrics(nodes: [
                    'a' => new ProcessGraphNodeMetrics(observedCount: 1, medianDwellSeconds: 600),
                    'b' => new ProcessGraphNodeMetrics(observedCount: 1, medianDwellSeconds: 600),
                ])
            ),
            new MermaidProcessGraphRenderOptions(view: MermaidProcessGraphRenderOptions::VIEW_DWELL)
        );

        self::assertStringContainsString('n_a["A"]:::dwell-scale-3', $mermaid);
        self::assertStringContainsString('n_b["B"]:::dwell-scale-3', $mermaid);
    }

    public function testMermaidDeviationViewMarksDeviationEdges(): void
    {
        $graph = new ProcessGraph(
            'test',
            'draft',
            [
                'a' => new ProcessGraphNode('a', 'A', ProcessGraphNode::TYPE_TASK),
                'b' => new ProcessGraphNode('b', 'B', ProcessGraphNode::TYPE_TASK),
            ],
            [
                new ProcessGraphEdge('a', 'b'),
            ]
        );
        $enrichedGraph = new EnrichedProcessGraph(
            $graph,
            new ProcessGraphMetrics(edges: [
                ProcessGraphMetrics::edgeKey('a', 'b') => new ProcessGraphEdgeMetrics('a', 'b', observedCount: 1, deviationCount: 1),
            ])
        );

        $mermaid = (new MermaidProcessGraphRenderer())->render(
            $enrichedGraph,
            new MermaidProcessGraphRenderOptions(view: MermaidProcessGraphRenderOptions::VIEW_DEVIATIONS)
        );

        self::assertStringContainsString('n_a -.-> n_b', $mermaid);
        self::assertStringContainsString('linkStyle 0 stroke:#dc2626,stroke-dasharray: 5 5;', $mermaid);
    }

    public function testMermaidFlowViewColorsNodesByFlowCount(): void
    {
        $graph = new ProcessGraph(
            'test',
            'draft',
            [
                'task_required' => new ProcessGraphNode('task_required', 'Required Task', ProcessGraphNode::TYPE_TASK, true),
                'task_high' => new ProcessGraphNode('task_high', 'High Task', ProcessGraphNode::TYPE_TASK),
                'decision:route' => new ProcessGraphNode('decision:route', 'route', ProcessGraphNode::TYPE_EXCLUSIVE_GATEWAY),
                'parallel_start:group' => new ProcessGraphNode('parallel_start:group', 'group start', ProcessGraphNode::TYPE_PARALLEL_START),
                'parallel_join:group' => new ProcessGraphNode('parallel_join:group', 'group complete', ProcessGraphNode::TYPE_PARALLEL_JOIN),
            ],
            []
        );
        $enrichedGraph = new EnrichedProcessGraph(
            $graph,
            new ProcessGraphMetrics(nodes: [
                'task_required' => new ProcessGraphNodeMetrics(flowCount: 1),
                'task_high' => new ProcessGraphNodeMetrics(flowCount: 10),
                'decision:route' => new ProcessGraphNodeMetrics(nodeType: ProcessGraphNode::TYPE_EXCLUSIVE_GATEWAY, flowCount: 5),
                'parallel_start:group' => new ProcessGraphNodeMetrics(nodeType: ProcessGraphNode::TYPE_PARALLEL_START, flowCount: 5),
                'parallel_join:group' => new ProcessGraphNodeMetrics(nodeType: ProcessGraphNode::TYPE_PARALLEL_JOIN, flowCount: 5),
            ])
        );

        $mermaid = (new MermaidProcessGraphRenderer())->render(
            $enrichedGraph,
            new MermaidProcessGraphRenderOptions(view: MermaidProcessGraphRenderOptions::VIEW_FLOW)
        );

        self::assertStringContainsString('n_task_required["Required Task"]:::required,flow-scale-0', $mermaid);
        self::assertStringContainsString('n_task_high["High Task"]:::flow-scale-7', $mermaid);
        self::assertMatchesRegularExpression('/n_decision_route\{route\}:::flow-scale-[0-7]/', $mermaid);
        self::assertMatchesRegularExpression('/n_parallel_start_group\{\{group start\}\}:::constraint,flow-scale-[0-7]/', $mermaid);
        self::assertMatchesRegularExpression('/n_parallel_join_group\{\{group complete\}\}:::constraint,flow-scale-[0-7]/', $mermaid);
        self::assertStringContainsString('classDef flow-scale-7 fill:#fee2e2;', $mermaid);
        self::assertStringNotContainsString('classDef flow-scale-7 fill:#fee2e2,stroke:', $mermaid);
    }

    public function testMermaidFlowViewHandlesEqualPercentiles(): void
    {
        $graph = new ProcessGraph(
            'test',
            'draft',
            [
                'a' => new ProcessGraphNode('a', 'A', ProcessGraphNode::TYPE_TASK),
                'b' => new ProcessGraphNode('b', 'B', ProcessGraphNode::TYPE_TASK),
            ],
            []
        );
        $enrichedGraph = new EnrichedProcessGraph(
            $graph,
            new ProcessGraphMetrics(nodes: [
                'a' => new ProcessGraphNodeMetrics(flowCount: 3),
                'b' => new ProcessGraphNodeMetrics(flowCount: 3),
            ])
        );

        $mermaid = (new MermaidProcessGraphRenderer())->render(
            $enrichedGraph,
            new MermaidProcessGraphRenderOptions(view: MermaidProcessGraphRenderOptions::VIEW_FLOW)
        );

        self::assertStringContainsString('n_a["A"]:::flow-scale-3', $mermaid);
        self::assertStringContainsString('n_b["B"]:::flow-scale-3', $mermaid);
    }

    public function testMermaidCombinedViewCombinesCountDwellAndDeviationStyles(): void
    {
        $graph = new ProcessGraph(
            'test',
            'draft',
            [
                'a' => new ProcessGraphNode('a', 'A', ProcessGraphNode::TYPE_TASK),
                'b' => new ProcessGraphNode('b', 'B', ProcessGraphNode::TYPE_TASK),
            ],
            [
                new ProcessGraphEdge('a', 'b'),
            ]
        );
        $enrichedGraph = new EnrichedProcessGraph(
            $graph,
            new ProcessGraphMetrics(
                nodes: [
                    'a' => new ProcessGraphNodeMetrics(observedCount: 1, medianDwellSeconds: 100000, deviationCount: 1, flowCount: 20),
                ],
                edges: [
                    ProcessGraphMetrics::edgeKey('a', 'b') => new ProcessGraphEdgeMetrics('a', 'b', observedCount: 6, deviationCount: 2),
                ]
            )
        );

        $mermaid = (new MermaidProcessGraphRenderer())->render(
            $enrichedGraph,
            new MermaidProcessGraphRenderOptions(view: MermaidProcessGraphRenderOptions::VIEW_COMBINED)
        );

        self::assertStringContainsString('Node color = dwell time, edge width = volume, red dashed edges = deviations.', $mermaid);
        self::assertStringContainsString('n_a["A"]:::dwell-scale-3,node-deviation', $mermaid);
        self::assertStringNotContainsString('n_a["A"]:::flow-scale-', $mermaid);
        self::assertStringContainsString('n_a -.->|"count 6"| n_b', $mermaid);
        self::assertStringContainsString('linkStyle 0 stroke-width:6px,stroke:#dc2626,stroke-dasharray: 5 5;', $mermaid);
    }

    public function testMetricsProjectionMapsDecisionTransitionToGatewayEdges(): void
    {
        $template = $this->template();
        $graph = (new ProcessTemplateGraphFactory())->create($template);
        $enrichedGraph = (new ProcessGraphMetricsFactory())->enrich($graph, $this->flowReport([
            ['from' => '01 Rechnungen pruefen', 'to' => '03 Freigabe_klein', 'count' => 2],
        ]), $template);

        $mermaid = (new MermaidProcessGraphRenderer())->render(
            $enrichedGraph,
            new MermaidProcessGraphRenderOptions(view: MermaidProcessGraphRenderOptions::VIEW_COMBINED)
        );

        self::assertStringContainsString('n_01_Rechnungen_pruefen -->|"count 2"| n_decision_route_after_pruefung', $mermaid);
        self::assertStringContainsString('n_decision_route_after_pruefung -->|"[2] amount_net gt 50; count 2"| n_03_Freigabe_klein', $mermaid);
        self::assertStringNotContainsString('n_01_Rechnungen_pruefen -.->|"count 2"| n_03_Freigabe_klein', $mermaid);
    }

    public function testMetricsProjectionMapsSecondDecisionTransitionToGatewayEdges(): void
    {
        $template = $this->template();
        $graph = (new ProcessTemplateGraphFactory())->create($template);
        $enrichedGraph = (new ProcessGraphMetricsFactory())->enrich($graph, $this->flowReport([
            ['from' => '03 Freigabe_klein', 'to' => '04 Freigabe_gross', 'count' => 1],
        ]), $template);

        $mermaid = (new MermaidProcessGraphRenderer())->render(
            $enrichedGraph,
            new MermaidProcessGraphRenderOptions(view: MermaidProcessGraphRenderOptions::VIEW_COMBINED)
        );

        self::assertStringContainsString('n_03_Freigabe_klein -->|"count 1"| n_decision_freigabe_ab_1000', $mermaid);
        self::assertStringContainsString('n_decision_freigabe_ab_1000 -->|"[1] amount_net gt 1000; count 1"| n_04_Freigabe_gross', $mermaid);
        self::assertStringNotContainsString('n_03_Freigabe_klein -.->|"count 1"| n_04_Freigabe_gross', $mermaid);
    }

    public function testMetricsProjectionMapsDecisionToParallelGroupStart(): void
    {
        $template = $this->template();
        $graph = (new ProcessTemplateGraphFactory())->create($template);
        $enrichedGraph = (new ProcessGraphMetricsFactory())->enrich($graph, $this->flowReport([
            ['from' => '03 Freigabe_klein', 'to' => '07 Zahlungseingang erwartet', 'count' => 1],
        ]), $template);

        $mermaid = (new MermaidProcessGraphRenderer())->render(
            $enrichedGraph,
            new MermaidProcessGraphRenderOptions(view: MermaidProcessGraphRenderOptions::VIEW_COMBINED)
        );

        self::assertStringContainsString('n_decision_freigabe_ab_1000 -->|"[else]; count 1"| n_parallel_start_buchen_und_zahlung', $mermaid);
        self::assertStringContainsString('n_parallel_start_buchen_und_zahlung -->|"count 1"| n_07_Zahlungseingang_erwartet', $mermaid);
        self::assertStringNotContainsString('n_03_Freigabe_klein -.->|"count 1"| n_07_Zahlungseingang_erwartet', $mermaid);
    }

    public function testMetricsProjectionDoesNotMarkAnyOrderGroupInternalTransitionsAsUnexpected(): void
    {
        $template = $this->template();
        $graph = (new ProcessTemplateGraphFactory())->create($template);
        $enrichedGraph = (new ProcessGraphMetricsFactory())->enrich($graph, $this->flowReport([
            ['from' => '05 Ausgangsrechnung buchen', 'to' => '07 Zahlungseingang erwartet', 'count' => 1],
            ['from' => '07 Zahlungseingang erwartet', 'to' => '05 Ausgangsrechnung buchen', 'count' => 1],
        ]), $template);

        $mermaid = (new MermaidProcessGraphRenderer())->render(
            $enrichedGraph,
            new MermaidProcessGraphRenderOptions(view: MermaidProcessGraphRenderOptions::VIEW_COMBINED)
        );

        self::assertStringNotContainsString('n_05_Ausgangsrechnung_buchen -.->|"count 1"| n_07_Zahlungseingang_erwartet', $mermaid);
        self::assertStringNotContainsString('n_07_Zahlungseingang_erwartet -.->|"count 1"| n_05_Ausgangsrechnung_buchen', $mermaid);
    }

    public function testMetricsProjectionMapsParallelGroupCompleteToJoinEdges(): void
    {
        $template = $this->template();
        $graph = (new ProcessTemplateGraphFactory())->create($template);
        $enrichedGraph = (new ProcessGraphMetricsFactory())->enrich($graph, $this->flowReport([
            ['from' => '05 Ausgangsrechnung buchen', 'to' => '09 Rechnungen Abschluss', 'count' => 1],
            ['from' => '07 Zahlungseingang erwartet', 'to' => '09 Rechnungen Abschluss', 'count' => 1],
        ]), $template);

        $mermaid = (new MermaidProcessGraphRenderer())->render(
            $enrichedGraph,
            new MermaidProcessGraphRenderOptions(view: MermaidProcessGraphRenderOptions::VIEW_COMBINED)
        );

        self::assertStringContainsString('n_05_Ausgangsrechnung_buchen -->|"count 1"| n_parallel_join_buchen_und_zahlung', $mermaid);
        self::assertStringContainsString('n_07_Zahlungseingang_erwartet -->|"count 1"| n_parallel_join_buchen_und_zahlung', $mermaid);
        self::assertStringContainsString('n_parallel_join_buchen_und_zahlung -->|"count 2"| n_09_Rechnungen_Abschluss', $mermaid);
        self::assertStringNotContainsString('n_05_Ausgangsrechnung_buchen -.->|"count 1"| n_09_Rechnungen_Abschluss', $mermaid);
        self::assertStringNotContainsString('n_07_Zahlungseingang_erwartet -.->|"count 1"| n_09_Rechnungen_Abschluss', $mermaid);
    }

    public function testMetricsProjectionKeepsUnexpectedTransitionsAsRedObservedOnlyEdges(): void
    {
        $template = $this->template();
        $graph = (new ProcessTemplateGraphFactory())->create($template);
        $enrichedGraph = (new ProcessGraphMetricsFactory())->enrich($graph, $this->flowReport([
            ['from' => '02 Versenden', 'to' => '01 Rechnungen pruefen', 'count' => 1],
        ]), $template);

        $mermaid = (new MermaidProcessGraphRenderer())->render(
            $enrichedGraph,
            new MermaidProcessGraphRenderOptions(view: MermaidProcessGraphRenderOptions::VIEW_COMBINED)
        );

        self::assertStringContainsString('n_02_Versenden -.->|"count 1"| n_01_Rechnungen_pruefen', $mermaid);
        self::assertStringContainsString('stroke:#dc2626,stroke-dasharray: 5 5', $mermaid);
    }

    public function testParallelGroupWithoutNextKeepsConstraintRendering(): void
    {
        $template = ProcessTemplateArrayFactory::fromArray([
            'key' => 'invoice',
            'steps' => [
                ['key' => 'booked'],
                ['key' => 'payment_expected'],
            ],
            'parallel_groups' => [
                [
                    'key' => 'booking_and_payment',
                    'required_steps' => ['booked', 'payment_expected'],
                    'order' => 'any',
                ],
            ],
        ]);

        $mermaid = (new MermaidProcessGraphRenderer())->render((new ProcessTemplateGraphFactory())->create($template));

        self::assertStringContainsString('n_parallel_booking_and_payment[[Constraint: booking_and_payment, required steps: booked, payment_expected, order:any]]:::constraint', $mermaid);
        self::assertStringContainsString('n_booked -.->|"part of"| n_parallel_booking_and_payment', $mermaid);
        self::assertStringNotContainsString('n_parallel_join_booking_and_payment', $mermaid);
    }

    private function template(): \App\Intelligence\Domain\ProcessTemplate
    {
        $data = Yaml::parseFile(dirname(__DIR__, 3).'/templates/ai-rechnungen.yaml');
        self::assertIsArray($data);

        return ProcessTemplateArrayFactory::fromArray($data);
    }

    /**
     * @param array<int, array<string, mixed>> $transitions
     * @return array<string, mixed>
     */
    private function flowReport(array $transitions): array
    {
        return [
            'flow_heatmap' => [
                'transitions' => $transitions,
            ],
        ];
    }

    /**
     * @param array<int, \App\Intelligence\Domain\ProcessGraphEdge> $edges
     */
    private function hasEdge(array $edges, string $from, string $to, ?string $label, string $style = ProcessGraphEdge::STYLE_FLOW): bool
    {
        foreach ($edges as $edge) {
            if ($edge->from === $from && $edge->to === $to && $edge->label === $label && $edge->style === $style) {
                return true;
            }
        }

        return false;
    }
}
