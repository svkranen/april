<?php

namespace App\Tests\Intelligence\Bpmn;

use App\Intelligence\Bpmn\BpmnSvgRenderer;
use App\Intelligence\Bpmn\BpmnSvgRenderOptions;
use App\Intelligence\Bpmn\ProcessTemplateBpmnViewBuilder;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateDecisionPoint;
use App\Intelligence\Domain\ProcessTemplateDecisionRule;
use App\Intelligence\Domain\ProcessTemplateParallelGroup;
use App\Intelligence\Domain\ProcessTemplateRuleCondition;
use App\Intelligence\Domain\ProcessTemplateStep;
use PHPUnit\Framework\TestCase;

final class BpmnSvgRendererTest extends TestCase
{
    public function testRendersSvgTasksGatewaysAndUnexpectedEdges(): void
    {
        $svg = (new BpmnSvgRenderer())->render($this->view());

        self::assertStringContainsString('<svg xmlns="http://www.w3.org/2000/svg"', $svg);
        self::assertStringContainsString('<rect', $svg);
        self::assertStringContainsString('data-node-id="task:invoice_checked"', $svg);
        self::assertStringContainsString('<polygon', $svg);
        self::assertStringContainsString('data-node-id="gateway:approval_route"', $svg);
        self::assertStringContainsString('data-edge-status="observed_unexpected"', $svg);
        self::assertStringContainsString('marker-end="url(#arrow-unexpected)"', $svg);
        self::assertStringContainsString('marker-end="url(#arrow-observed)"', $svg);
        self::assertStringContainsString('stroke="#dc2626"', $svg);
        self::assertStringContainsString('stroke-dasharray="7 5"', $svg);
    }

    public function testParallelGroupContainsMemberTasksVisually(): void
    {
        $svg = (new BpmnSvgRenderer())->render($this->view());

        self::assertStringContainsString('data-node-id="parallel:booking_and_payment"', $svg);
        self::assertStringContainsString('data-node-id="task:booked"', $svg);
        self::assertStringContainsString('data-node-id="task:payment_expected"', $svg);
        self::assertLessThan(
            strpos($svg, 'data-node-id="task:booked"'),
            strpos($svg, 'data-node-id="parallel:booking_and_payment"')
        );
        self::assertLessThan(
            strpos($svg, 'data-node-id="task:payment_expected"'),
            strpos($svg, 'data-node-id="parallel:booking_and_payment"')
        );
    }

    public function testLongLabelsAreWrappedWithTspans(): void
    {
        $svg = (new BpmnSvgRenderer())->render((new ProcessTemplateBpmnViewBuilder())->build(new ProcessTemplate(
            'invoice',
            '1',
            steps: [
                new ProcessTemplateStep('01 Very Long Invoice Review Step Name'),
            ],
            requiredStepKeys: ['01 Very Long Invoice Review Step Name']
        )));

        self::assertStringContainsString('<tspan', $svg);
        self::assertStringContainsString('Very Long Invoice', $svg);
        self::assertStringContainsString('Review Step Name', $svg);
    }

    public function testViewAndMinimumUnexpectedCountFilterEdges(): void
    {
        $renderer = new BpmnSvgRenderer();

        $deviations = $renderer->render($this->view(), new BpmnSvgRenderOptions('deviations', 2));
        self::assertStringContainsString('2x 10%', $deviations);
        self::assertStringNotContainsString('5x 25%', $deviations);

        $filtered = $renderer->render($this->view(), new BpmnSvgRenderOptions('combined', 3));
        self::assertStringNotContainsString('2x 10%', $filtered);
    }

    public function testSummaryViewShowsExpectedStructureAndNodeHeatmapOnly(): void
    {
        $svg = (new BpmnSvgRenderer())->render($this->view(), new BpmnSvgRenderOptions('summary', 2));

        self::assertStringNotContainsString('data-edge-status="observed_unexpected"', $svg);
        self::assertStringNotContainsString('stroke-dasharray="7 5"', $svg);
        self::assertStringNotContainsString('5x 25%', $svg);
        self::assertStringContainsString('data-node-id="parallel:booking_and_payment"', $svg);
        self::assertStringContainsString('1x · Ø 1.0 min · open 1', $svg);
    }

    public function testSummaryProcessLayoutShowsReadableSollStructure(): void
    {
        $svg = (new BpmnSvgRenderer())->render(
            $this->processView(),
            new BpmnSvgRenderOptions('summary', 2, 1200, true, 'process')
        );

        self::assertStringContainsString('data-layout="process"', $svg);
        self::assertStringNotContainsString('data-edge-status="observed_unexpected"', $svg);
        self::assertStringNotContainsString('stroke-dasharray="7 5"', $svg);
        self::assertStringNotContainsString('data-edge-source="required_step"', $svg);
        self::assertStringContainsString('data-node-id="parallel:buchen_und_zahlung"', $svg);
        self::assertStringContainsString('data-node-id="task:05 Ausgangsrechnung buchen"', $svg);
        self::assertStringContainsString('data-node-id="task:07 Zahlungseingang erwartet"', $svg);
        self::assertStringContainsString('22x · Ø 0.5 min · open 0', $svg);
        self::assertStringContainsString('documents 22 · Ø 0.5 min · max open 0', $svg);
        self::assertStringContainsString('marker-end="url(#arrow-expected)"', $svg);
        self::assertStringContainsString('&gt; 50', $svg);
        self::assertStringContainsString('RE-Ausgang', $svg);
        self::assertStringContainsString('data-node-role="end"', $svg);
        self::assertStringContainsString('Ende', $svg);
        self::assertStringContainsString('data-open-documents="20"', $svg);
        self::assertStringContainsString('fill="#fecaca"', $svg);
    }

    public function testBottleneckProcessLayoutFocusesOnTaskHeatmapAndHidesMostEdges(): void
    {
        $svg = (new BpmnSvgRenderer())->render(
            $this->processView(),
            new BpmnSvgRenderOptions('bottleneck', 2, 1200, true, 'process')
        );

        self::assertStringContainsString('data-view="bottleneck"', $svg);
        self::assertStringContainsString('data-edge-source="decision_point"', $svg);
        self::assertStringNotContainsString('data-edge-source="decision_rule"', $svg);
        self::assertStringNotContainsString('data-edge-status="observed_unexpected"', $svg);
        self::assertStringContainsString('data-open-documents="20"', $svg);
    }

    public function testSummaryDecisionLabelsAreShort(): void
    {
        $svg = (new BpmnSvgRenderer())->render((new ProcessTemplateBpmnViewBuilder())->build(new ProcessTemplate(
            'invoice',
            '1',
            steps: [
                new ProcessTemplateStep('invoice_checked'),
                new ProcessTemplateStep('booked'),
            ],
            decisionPoints: [
                new ProcessTemplateDecisionPoint(
                    'approval_route',
                    'invoice_checked',
                    ['invoice_direction', 'amount'],
                    [
                        new ProcessTemplateDecisionRule(
                            new ProcessTemplateRuleCondition('invoice_direction', 'eq', 'RE - Ausgang'),
                            'booked'
                        ),
                        new ProcessTemplateDecisionRule(
                            new ProcessTemplateRuleCondition('amount', 'gt', 50),
                            'booked'
                        ),
                    ]
                ),
            ],
            requiredStepKeys: ['invoice_checked']
        )), new BpmnSvgRenderOptions('summary'));

        self::assertStringContainsString('&gt; 50', $svg);
        self::assertStringContainsString('RE-Ausgang', $svg);
        self::assertStringNotContainsString('RE - Ausgang', $svg);
        self::assertStringNotContainsString('gt 50', $svg);
    }

    private function view(): \App\Intelligence\Bpmn\BpmnProcessView
    {
        return (new ProcessTemplateBpmnViewBuilder())->build($this->template(), [
            'flow_heatmap' => [
                'transitions' => [
                    [
                        'from' => 'invoice_checked',
                        'to' => 'booked',
                        'count' => 5,
                        'percentage' => 25.0,
                        'intensity' => 1.0,
                    ],
                    [
                        'from' => 'payment_expected',
                        'to' => 'manual_review',
                        'count' => 2,
                        'percentage' => 10.0,
                        'intensity' => 0.4,
                    ],
                ],
            ],
            'duration_heatmap' => [
                'steps' => [
                    [
                        'step' => 'payment_expected',
                        'historical' => [
                            'completed_documents' => 1,
                            'avg_duration_minutes' => 1.0,
                        ],
                        'current' => [
                            'open_documents' => 1,
                        ],
                        'intensity' => [
                            'current_backlog_count' => 0.5,
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function template(): ProcessTemplate
    {
        return new ProcessTemplate(
            'invoice',
            '1',
            steps: [
                new ProcessTemplateStep('invoice_checked'),
                new ProcessTemplateStep('booked'),
                new ProcessTemplateStep('payment_expected'),
                new ProcessTemplateStep('manual_review'),
            ],
            parallelGroups: [
                new ProcessTemplateParallelGroup(
                    'booking_and_payment',
                    null,
                    ['booked', 'payment_expected'],
                    'any'
                ),
            ],
            decisionPoints: [
                new ProcessTemplateDecisionPoint(
                    'approval_route',
                    'invoice_checked',
                    ['amount'],
                    [
                        new ProcessTemplateDecisionRule(
                            new ProcessTemplateRuleCondition('amount', 'gt', 50),
                            'booked'
                        ),
                    ]
                ),
            ],
            requiredStepKeys: ['invoice_checked']
        );
    }

    private function processView(): \App\Intelligence\Bpmn\BpmnProcessView
    {
        return (new ProcessTemplateBpmnViewBuilder())->build(new ProcessTemplate(
            'ai-rechnungen',
            '1',
            steps: [
                new ProcessTemplateStep('01 Rechnungen pruefen'),
                new ProcessTemplateStep('02 Versenden'),
                new ProcessTemplateStep('03 Freigabe_klein'),
                new ProcessTemplateStep('04 Freigabe_gross'),
                new ProcessTemplateStep('05 Ausgangsrechnung buchen'),
                new ProcessTemplateStep('07 Zahlungseingang erwartet'),
                new ProcessTemplateStep('09 Rechnungen Abschluss'),
            ],
            parallelGroups: [
                new ProcessTemplateParallelGroup(
                    'buchen_und_zahlung',
                    null,
                    ['05 Ausgangsrechnung buchen', '07 Zahlungseingang erwartet'],
                    'any'
                ),
            ],
            decisionPoints: [
                new ProcessTemplateDecisionPoint(
                    'route_after_pruefung',
                    '01 Rechnungen pruefen',
                    ['invoice_direction', 'amount_net'],
                    [
                        new ProcessTemplateDecisionRule(
                            new ProcessTemplateRuleCondition('invoice_direction', 'eq', 'RE - Ausgang'),
                            '02 Versenden'
                        ),
                        new ProcessTemplateDecisionRule(
                            new ProcessTemplateRuleCondition('amount_net', 'gt', 50),
                            '03 Freigabe_klein'
                        ),
                        new ProcessTemplateDecisionRule(null, '05 Ausgangsrechnung buchen'),
                    ]
                ),
                new ProcessTemplateDecisionPoint(
                    'freigabe_ab_1000',
                    '03 Freigabe_klein',
                    ['amount_net'],
                    [
                        new ProcessTemplateDecisionRule(
                            new ProcessTemplateRuleCondition('amount_net', 'gt', 1000),
                            '04 Freigabe_gross'
                        ),
                        new ProcessTemplateDecisionRule(null, '05 Ausgangsrechnung buchen'),
                    ]
                ),
            ],
            requiredStepKeys: ['01 Rechnungen pruefen', '09 Rechnungen Abschluss']
        ), [
            'flow_heatmap' => [
                'transitions' => [
                    [
                        'from' => '09 Rechnungen Abschluss',
                        'to' => '04 Freigabe_gross',
                        'count' => 2,
                        'percentage' => 8.7,
                        'intensity' => 0.2,
                    ],
                ],
            ],
            'duration_heatmap' => [
                'steps' => [
                    [
                        'step' => '05 Ausgangsrechnung buchen',
                        'historical' => [
                            'completed_documents' => 22,
                            'avg_duration_minutes' => 0.5,
                        ],
                        'current' => [
                            'open_documents' => 0,
                        ],
                        'intensity' => [
                            'historical_duration' => 0.4,
                        ],
                    ],
                    [
                        'step' => '09 Rechnungen Abschluss',
                        'historical' => [
                            'completed_documents' => 2,
                            'avg_duration_minutes' => 12.0,
                        ],
                        'current' => [
                            'open_documents' => 20,
                        ],
                        'intensity' => [
                            'current_backlog_count' => 1.0,
                        ],
                    ],
                ],
            ],
        ]);
    }
}
