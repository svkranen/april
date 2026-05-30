<?php

namespace App\Tests\Intelligence\Bpmn;

use App\Intelligence\Bpmn\ProcessTemplateBpmnViewBuilder;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateDecisionPoint;
use App\Intelligence\Domain\ProcessTemplateDecisionRule;
use App\Intelligence\Domain\ProcessTemplateParallelGroup;
use App\Intelligence\Domain\ProcessTemplateRuleCondition;
use App\Intelligence\Domain\ProcessTemplateStep;
use App\Intelligence\Domain\ProcessTemplateTransition;
use PHPUnit\Framework\TestCase;

final class ProcessTemplateBpmnViewBuilderTest extends TestCase
{
    public function testBuildsViewFromTemplate(): void
    {
        $view = (new ProcessTemplateBpmnViewBuilder())->build($this->template())->toArray();

        self::assertSame('invoice', $view['template_key']);
        self::assertSame('1', $view['template_version']);
        self::assertNotNull($this->node($view, 'task:invoice_checked'));
        self::assertNotNull($this->node($view, 'gateway:approval_route'));
        self::assertNotNull($this->node($view, 'parallel:booking_and_payment'));
        self::assertNotNull($this->edge($view, 'edge:required:invoice_checked:invoice_finished'));
        self::assertNotNull($this->edge($view, 'edge:decision-rule:approval_route:booked:amount gt 50'));
    }

    public function testMapsHeatmapMetricsAndObservedTransitions(): void
    {
        $view = (new ProcessTemplateBpmnViewBuilder())->build($this->template(), $this->heatmap())->toArray();

        $task = $this->node($view, 'task:booked');
        self::assertSame(12, $task['metrics']['historical_count']);
        self::assertSame(1.5, $task['metrics']['avg_duration']);
        self::assertSame(2, $task['metrics']['open_documents']);
        self::assertSame(0.8, $task['metrics']['intensity']);

        $allowedParallelEdge = $this->edge($view, 'edge:parallel-any:booking_and_payment:booked:payment_expected');
        self::assertSame('observed_allowed', $allowedParallelEdge['status']);
        self::assertSame(5, $allowedParallelEdge['observed_count']);
        self::assertTrue($allowedParallelEdge['is_allowed']);

        $unexpectedEdge = $this->edge($view, 'edge:observed:payment_expected:manual_review');
        self::assertSame('observed_unexpected', $unexpectedEdge['status']);
        self::assertSame(2, $unexpectedEdge['observed_count']);
        self::assertFalse($unexpectedEdge['is_allowed']);
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
                new ProcessTemplateStep('invoice_finished'),
            ],
            transitions: [
                new ProcessTemplateTransition('invoice_checked', 'invoice_finished'),
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
                        new ProcessTemplateDecisionRule(
                            null,
                            'invoice_finished',
                            true
                        ),
                    ]
                ),
            ],
            requiredStepKeys: ['invoice_checked', 'invoice_finished']
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function heatmap(): array
    {
        return [
            'flow_heatmap' => [
                'transitions' => [
                    [
                        'from' => 'booked',
                        'to' => 'payment_expected',
                        'count' => 5,
                        'percentage' => 25.0,
                        'intensity' => 1.0,
                        'is_allowed' => false,
                    ],
                    [
                        'from' => 'payment_expected',
                        'to' => 'manual_review',
                        'count' => 2,
                        'percentage' => 10.0,
                        'intensity' => 0.4,
                        'is_allowed' => false,
                    ],
                ],
            ],
            'duration_heatmap' => [
                'steps' => [
                    [
                        'step' => 'booked',
                        'historical' => [
                            'completed_documents' => 12,
                            'avg_duration_minutes' => 1.5,
                        ],
                        'current' => [
                            'open_documents' => 2,
                        ],
                        'intensity' => [
                            'historical_duration' => 0.2,
                            'current_backlog_age' => 0.8,
                            'current_backlog_count' => 0.4,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $view
     * @return array<string, mixed>|null
     */
    private function node(array $view, string $id): ?array
    {
        foreach ($view['nodes'] as $node) {
            if ($node['id'] === $id) {
                return $node;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $view
     * @return array<string, mixed>|null
     */
    private function edge(array $view, string $id): ?array
    {
        foreach ($view['edges'] as $edge) {
            if ($edge['id'] === $id) {
                return $edge;
            }
        }

        return null;
    }
}
