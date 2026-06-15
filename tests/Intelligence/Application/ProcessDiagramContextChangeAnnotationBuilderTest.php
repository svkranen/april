<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\DocumentTimelineEventRow;
use App\Intelligence\Application\ProcessDiagramContextChangeAnnotationBuilder;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateDecisionPoint;
use App\Intelligence\Domain\ProcessTemplateDecisionRule;
use App\Intelligence\Domain\ProcessTemplateRuleCondition;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ProcessDiagramContextChangeAnnotationBuilderTest extends TestCase
{
    public function testBuildsAnnotationOnlyForContextChangeAfterDecisionViolation(): void
    {
        $template = new ProcessTemplate(
            'invoice-process',
            decisionPoints: [
                new ProcessTemplateDecisionPoint(
                    'route_after_pruefung',
                    'invoice_checked',
                    ['amount_net'],
                    [
                        new ProcessTemplateDecisionRule(
                            new ProcessTemplateRuleCondition('amount_net', 'gt', 1000),
                            'large_approval'
                        ),
                    ]
                ),
            ]
        );

        $annotations = (new ProcessDiagramContextChangeAnnotationBuilder())->build($template, [
            $this->event('evt-1', 'invoice_checked', ['amount_net' => 4149788, 'cost_center' => '100']),
            $this->event('evt-2', 'small_approval', ['amount_net' => 41.49, 'cost_center' => '200']),
        ]);

        self::assertCount(1, $annotations);
        self::assertSame('amount_net', $annotations[0]->field);
        self::assertSame(4149788, $annotations[0]->from);
        self::assertSame(41.49, $annotations[0]->to);
        self::assertSame(['route_after_pruefung'], $annotations[0]->affectedDecisions);
        self::assertSame('decision:route_after_pruefung', $annotations[0]->targetNodeId);
    }

    public function testDoesNotAnnotateRelevantChangeWithoutDecisionViolation(): void
    {
        $template = new ProcessTemplate(
            'invoice-process',
            decisionPoints: [
                new ProcessTemplateDecisionPoint(
                    'route_after_pruefung',
                    'invoice_checked',
                    ['amount_net'],
                    [
                        new ProcessTemplateDecisionRule(
                            new ProcessTemplateRuleCondition('amount_net', 'gt', 1000),
                            'large_approval'
                        ),
                    ]
                ),
            ]
        );

        $annotations = (new ProcessDiagramContextChangeAnnotationBuilder())->build($template, [
            $this->event('evt-1', 'invoice_checked', ['amount_net' => 4149788]),
            $this->event('evt-2', 'large_approval', ['amount_net' => 41.49]),
        ]);

        self::assertSame([], $annotations);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function event(string $externalEventKey, string $stepKey, array $context): DocumentTimelineEventRow
    {
        return new DocumentTimelineEventRow(
            $externalEventKey,
            $stepKey,
            $stepKey,
            'invoice-process',
            1,
            new DateTimeImmutable('2026-06-01T09:00:00+00:00'),
            new DateTimeImmutable('2026-06-01T09:00:00+00:00'),
            null,
            null,
            [
                'attributes' => $context,
                'fields' => array_keys($context),
                'warnings' => [],
            ]
        );
    }
}
