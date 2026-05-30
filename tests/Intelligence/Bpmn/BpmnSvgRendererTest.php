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
        self::assertStringContainsString('Ausgang', $svg);
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
}
