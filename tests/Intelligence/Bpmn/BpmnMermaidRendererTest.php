<?php

namespace App\Tests\Intelligence\Bpmn;

use App\Intelligence\Bpmn\BpmnMermaidRenderer;
use App\Intelligence\Bpmn\ProcessTemplateBpmnViewBuilder;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateDecisionPoint;
use App\Intelligence\Domain\ProcessTemplateDecisionRule;
use App\Intelligence\Domain\ProcessTemplateParallelGroup;
use App\Intelligence\Domain\ProcessTemplateRuleCondition;
use App\Intelligence\Domain\ProcessTemplateStep;
use PHPUnit\Framework\TestCase;

final class BpmnMermaidRendererTest extends TestCase
{
    public function testRendersTasksGatewaysAndObservedUnexpectedEdges(): void
    {
        $view = (new ProcessTemplateBpmnViewBuilder())->build($this->template(), [
            'flow_heatmap' => [
                'transitions' => [
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
                            'open_documents' => 12,
                        ],
                        'intensity' => [
                            'current_backlog_count' => 1.0,
                        ],
                    ],
                ],
            ],
        ]);

        $mermaid = (new BpmnMermaidRenderer())->render($view);

        self::assertStringContainsString('flowchart TD', $mermaid);
        self::assertStringContainsString('n_task_invoice_checked["invoice_checked (required)"]', $mermaid);
        self::assertStringContainsString('n_gateway_approval_route{"approval_route"}', $mermaid);
        self::assertStringContainsString('n_parallel_booking_and_payment["Parallel: booking_and_payment (booked, payment_expected)"]', $mermaid);
        self::assertStringContainsString('2x · 10%', $mermaid);
        self::assertStringContainsString('stroke:#dc2626', $mermaid);
        self::assertStringContainsString('class n_task_payment_expected hot_node', $mermaid);
    }

    public function testSanitizesUnsafeEdgeLabels(): void
    {
        $view = (new ProcessTemplateBpmnViewBuilder())->build(
            new ProcessTemplate(
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
                        ['invoice_direction'],
                        [
                            new ProcessTemplateDecisionRule(
                                new ProcessTemplateRuleCondition('invoice_direction', 'eq', 'RE - Ausgang | Spezial'),
                                'booked'
                            ),
                        ]
                    ),
                ],
                requiredStepKeys: ['invoice_checked']
            ),
            [
                'flow_heatmap' => [
                    'transitions' => [
                        [
                            'from' => 'invoice_checked',
                            'to' => 'booked',
                            'count' => 6,
                            'percentage' => 26.09,
                            'intensity' => 1.0,
                        ],
                    ],
                ],
            ]
        );

        $mermaid = (new BpmnMermaidRenderer())->render($view);

        self::assertStringContainsString('RE - Ausgang / Spezial · 6x · 26%', $mermaid);
        self::assertStringNotContainsString('\"', $mermaid);
        self::assertStringNotContainsString('\\"', $mermaid);
        self::assertStringNotContainsString('RE - Ausgang | Spezial', $mermaid);
    }

    public function testRendersComparisonOperatorsAsHtmlEncodedSymbols(): void
    {
        $view = (new ProcessTemplateBpmnViewBuilder())->build(
            new ProcessTemplate(
                'invoice',
                '1',
                steps: [
                    new ProcessTemplateStep('invoice_checked'),
                    new ProcessTemplateStep('approval'),
                ],
                decisionPoints: [
                    new ProcessTemplateDecisionPoint(
                        'approval_route',
                        'invoice_checked',
                        ['amount_net'],
                        [
                            new ProcessTemplateDecisionRule(
                                new ProcessTemplateRuleCondition('amount_net', 'gt', 50),
                                'approval'
                            ),
                        ]
                    ),
                ],
                requiredStepKeys: ['invoice_checked']
            ),
            []
        );

        $mermaid = (new BpmnMermaidRenderer())->render($view, 'expected');

        self::assertStringContainsString('&gt; 50', $mermaid);
        self::assertStringNotContainsString('gt 50', $mermaid);
    }

    public function testExpectedViewDoesNotRenderUnexpectedObservedEdges(): void
    {
        $mermaid = (new BpmnMermaidRenderer())->render($this->viewWithUnexpectedEdges(), 'expected');

        self::assertStringContainsString('n_gateway_approval_route{"approval_route"}', $mermaid);
        self::assertStringNotContainsString('2x · 10%', $mermaid);
        self::assertStringNotContainsString('n_task_payment_expected -->|"2x', $mermaid);
    }

    public function testDeviationsViewRendersOnlyDeviationEdges(): void
    {
        $mermaid = (new BpmnMermaidRenderer())->render($this->viewWithUnexpectedEdges(), 'deviations');

        self::assertStringContainsString('2x · 10%', $mermaid);
        self::assertStringNotContainsString('gt 50', $mermaid);
        self::assertStringNotContainsString('5x · 25%', $mermaid);
    }

    public function testMinimumUnexpectedCountFiltersRareEdges(): void
    {
        $mermaid = (new BpmnMermaidRenderer())->render($this->viewWithUnexpectedEdges(), 'combined', 3);

        self::assertStringNotContainsString('2x · 10%', $mermaid);
    }

    public function testRequiredStepsAreNotRenderedAsDirectFlowEdges(): void
    {
        $view = (new ProcessTemplateBpmnViewBuilder())->build(new ProcessTemplate(
            'invoice',
            '1',
            steps: [
                new ProcessTemplateStep('invoice_checked'),
                new ProcessTemplateStep('invoice_finished'),
            ],
            requiredStepKeys: ['invoice_checked', 'invoice_finished']
        ));

        $mermaid = (new BpmnMermaidRenderer())->render($view, 'expected');

        self::assertStringContainsString('n_task_invoice_finished["invoice_finished (required)"]', $mermaid);
        self::assertStringNotContainsString('n_task_invoice_checked -->|"expected"| n_task_invoice_finished', $mermaid);
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

    private function viewWithUnexpectedEdges(): \App\Intelligence\Bpmn\BpmnProcessView
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
        ]);
    }
}
