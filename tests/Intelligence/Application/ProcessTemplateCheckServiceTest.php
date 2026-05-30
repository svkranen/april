<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\ProcessTemplateCheckService;
use App\Intelligence\Domain\ContextSnapshot;
use App\Intelligence\Domain\DocumentRef;
use App\Intelligence\Domain\ProcessEventRecord;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateDecisionPoint;
use App\Intelligence\Domain\ProcessTemplateDecisionRule;
use App\Intelligence\Domain\ProcessTemplateParallelGroup;
use App\Intelligence\Domain\ProcessTemplateStep;
use App\Intelligence\Domain\ProcessTemplateRuleCondition;
use App\Intelligence\Infrastructure\Process\InMemoryDocumentTimelineProvider;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class ProcessTemplateCheckServiceTest extends TestCase
{
    public function testCheckDocumentUsesProcessTemplateModel(): void
    {
        $service = new ProcessTemplateCheckService(
            new InMemoryDocumentTimelineProvider(
                [],
                [
                    $this->event(1, 'received', 0),
                    $this->event(2, 'checked', 1),
                    $this->event(3, 'approved', 2),
                ]
            )
        );

        $result = $service->checkDocument(
            'uuid-1',
            'invoice',
            new ProcessTemplate(
                'invoice',
                steps: [
                    new ProcessTemplateStep('received'),
                    new ProcessTemplateStep('checked'),
                    new ProcessTemplateStep('approved'),
                ]
            ),
            1
        );

        self::assertTrue($result->isOk());
        self::assertSame(['received', 'checked', 'approved'], $result->expectedSteps);
        self::assertSame(['received', 'checked', 'approved'], $result->actualSteps);
    }

    public function testCheckDocumentKeepsParallelGroupAnyOrderLogic(): void
    {
        $service = new ProcessTemplateCheckService(
            new InMemoryDocumentTimelineProvider(
                [],
                [
                    $this->event(1, 'sent', 0),
                    $this->event(2, 'payment_expected', 1),
                    $this->event(3, 'booked', 2),
                ]
            )
        );

        $result = $service->checkDocument(
            'uuid-1',
            'invoice',
            new ProcessTemplate(
                'invoice',
                steps: [
                    new ProcessTemplateStep('sent'),
                ],
                parallelGroups: [
                    new ProcessTemplateParallelGroup(
                        'booking_and_payment',
                        'sent',
                        ['booked', 'payment_expected'],
                        'any'
                    ),
                ]
            ),
            1
        );

        self::assertTrue($result->isOk());
        self::assertSame(['sent', 'booked', 'payment_expected'], $result->expectedSteps);
        self::assertSame(['sent', 'payment_expected', 'booked'], $result->actualSteps);
        self::assertSame(['Parallel Group satisfied: booking_and_payment'], $result->parallelGroupMessages);
    }

    public function testConditionalDecisionStepIsNotReportedAsGlobalMissingStep(): void
    {
        $service = new ProcessTemplateCheckService(
            new InMemoryDocumentTimelineProvider(
                [],
                [
                    $this->event(1, 'invoice_checked', 0),
                    $this->event(2, 'invoice_finished', 1),
                ],
                [
                    $this->snapshot('evt-1', ['amount' => 100]),
                ]
            )
        );

        $result = $service->checkDocument(
            'uuid-1',
            'invoice',
            new ProcessTemplate(
                'invoice',
                steps: [
                    new ProcessTemplateStep('invoice_checked'),
                    new ProcessTemplateStep('small_approval'),
                    new ProcessTemplateStep('invoice_finished'),
                ],
                decisionPoints: [
                    new ProcessTemplateDecisionPoint(
                        'approval_route',
                        'invoice_checked',
                        ['amount'],
                        [
                            new ProcessTemplateDecisionRule(
                                new ProcessTemplateRuleCondition('amount', 'gt', 1000),
                                'small_approval'
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
            ),
            1
        );

        self::assertTrue($result->isOk());
        self::assertNotContains('Missing step: small_approval', $result->deviations);
    }

    public function testRequiredStepsAreReportedMissingWhenAbsent(): void
    {
        $service = new ProcessTemplateCheckService(
            new InMemoryDocumentTimelineProvider(
                [],
                [
                    $this->event(1, 'invoice_checked', 0),
                ]
            )
        );

        $result = $service->checkDocument(
            'uuid-1',
            'invoice',
            new ProcessTemplate(
                'invoice',
                steps: [
                    new ProcessTemplateStep('invoice_checked'),
                    new ProcessTemplateStep('conditional_approval'),
                    new ProcessTemplateStep('invoice_finished'),
                ],
                requiredStepKeys: ['invoice_checked', 'invoice_finished']
            ),
            1
        );

        self::assertContains('Missing step: invoice_finished', $result->deviations);
        self::assertNotContains('Missing step: conditional_approval', $result->deviations);
    }

    public function testTemplateWithoutRequiredStepsKeepsAllStepsAsRequiredForCompatibility(): void
    {
        $service = new ProcessTemplateCheckService(
            new InMemoryDocumentTimelineProvider(
                [],
                [
                    $this->event(1, 'received', 0),
                    $this->event(2, 'archived', 1),
                ]
            )
        );

        $result = $service->checkDocument(
            'uuid-1',
            'invoice',
            new ProcessTemplate(
                'invoice',
                steps: [
                    new ProcessTemplateStep('received'),
                    new ProcessTemplateStep('manual_check'),
                    new ProcessTemplateStep('archived'),
                ]
            ),
            1
        );

        self::assertContains('Missing step: manual_check', $result->deviations);
    }

    public function testRequiredStepsKeepParallelGroupsSeparate(): void
    {
        $service = new ProcessTemplateCheckService(
            new InMemoryDocumentTimelineProvider(
                [],
                [
                    $this->event(1, 'sent', 0),
                    $this->event(2, 'payment_expected', 1),
                    $this->event(3, 'booked', 2),
                ]
            )
        );

        $result = $service->checkDocument(
            'uuid-1',
            'invoice',
            new ProcessTemplate(
                'invoice',
                steps: [
                    new ProcessTemplateStep('sent'),
                    new ProcessTemplateStep('booked'),
                    new ProcessTemplateStep('payment_expected'),
                ],
                parallelGroups: [
                    new ProcessTemplateParallelGroup(
                        'booking_and_payment',
                        'sent',
                        ['booked', 'payment_expected'],
                        'any'
                    ),
                ],
                requiredStepKeys: ['sent']
            ),
            1
        );

        self::assertTrue($result->isOk());
        self::assertSame(['sent', 'booked', 'payment_expected'], $result->expectedSteps);
        self::assertSame(['Parallel Group satisfied: booking_and_payment'], $result->parallelGroupMessages);
    }

    public function testCheckDocumentAcceptsCorrectDecisionPath(): void
    {
        $service = new ProcessTemplateCheckService(
            new InMemoryDocumentTimelineProvider(
                [],
                [
                    $this->event(1, 'invoice_checked', 0),
                    $this->event(2, 'gf_approval', 1),
                ],
                [
                    $this->snapshot('evt-1', ['amount' => 12000]),
                ]
            )
        );

        $result = $service->checkDocument(
            'uuid-1',
            'invoice',
            $this->templateWithDecisionPath('gf_approval'),
            1
        );

        self::assertTrue($result->isOk());
        self::assertSame([], $result->deviations);
    }

    public function testCheckDocumentReportsWrongDecisionPath(): void
    {
        $service = new ProcessTemplateCheckService(
            new InMemoryDocumentTimelineProvider(
                [],
                [
                    $this->event(1, 'invoice_checked', 0),
                    $this->event(2, 'department_approval', 1),
                ],
                [
                    $this->snapshot('evt-1', ['amount' => 12000]),
                ]
            )
        );

        $result = $service->checkDocument(
            'uuid-1',
            'invoice',
            $this->templateWithDecisionPath('department_approval'),
            1
        );

        self::assertContains(
            'Decision rule violation: approval_route expected gf_approval but got department_approval',
            $result->deviations
        );
    }

    public function testCheckDocumentUsesDecisionElseFallback(): void
    {
        $service = new ProcessTemplateCheckService(
            new InMemoryDocumentTimelineProvider(
                [],
                [
                    $this->event(1, 'invoice_checked', 0),
                    $this->event(2, 'department_approval', 1),
                ],
                [
                    $this->snapshot('evt-1', ['amount' => 5000]),
                ]
            )
        );

        $result = $service->checkDocument(
            'uuid-1',
            'invoice',
            $this->templateWithDecisionPath('department_approval'),
            1
        );

        self::assertTrue($result->isOk());
        self::assertSame([], $result->deviations);
    }

    public function testCheckDocumentReportsMissingDecisionContext(): void
    {
        $service = new ProcessTemplateCheckService(
            new InMemoryDocumentTimelineProvider(
                [],
                [
                    $this->event(1, 'invoice_checked', 0),
                    $this->event(2, 'gf_approval', 1),
                ]
            )
        );

        $result = $service->checkDocument(
            'uuid-1',
            'invoice',
            $this->templateWithDecisionPath('gf_approval'),
            1
        );

        self::assertContains(
            'Missing context for decision point approval_route: amount',
            $result->deviations
        );
    }

    public function testCheckDocumentReportsMissingDecisionContextFields(): void
    {
        $service = new ProcessTemplateCheckService(
            new InMemoryDocumentTimelineProvider(
                [],
                [
                    $this->event(1, 'invoice_checked', 0),
                    $this->event(2, 'department_approval', 1),
                ],
                [
                    $this->snapshot('evt-1', ['amount_net' => null]),
                ]
            )
        );

        $result = $service->checkDocument(
            'uuid-1',
            'invoice',
            new ProcessTemplate(
                'invoice',
                steps: [
                    new ProcessTemplateStep('invoice_checked'),
                    new ProcessTemplateStep('department_approval'),
                    new ProcessTemplateStep('gf_approval'),
                ],
                decisionPoints: [
                    new ProcessTemplateDecisionPoint(
                        'route_after_pruefung',
                        'invoice_checked',
                        ['invoice_direction', 'amount_net'],
                        [
                            new ProcessTemplateDecisionRule(
                                new ProcessTemplateRuleCondition('amount_net', 'gt', 1000),
                                'gf_approval'
                            ),
                            new ProcessTemplateDecisionRule(
                                null,
                                'department_approval',
                                true
                            ),
                        ]
                    ),
                ],
                requiredStepKeys: ['invoice_checked']
            ),
            1
        );

        self::assertContains(
            'Missing context for decision point route_after_pruefung: invoice_direction, amount_net',
            $result->deviations
        );
    }

    private function templateWithDecisionPath(string $nextStepKey): ProcessTemplate
    {
        return new ProcessTemplate(
            'invoice',
            steps: [
                new ProcessTemplateStep('invoice_checked'),
                new ProcessTemplateStep($nextStepKey),
            ],
            decisionPoints: [
                new ProcessTemplateDecisionPoint(
                    'approval_route',
                    'invoice_checked',
                    ['amount'],
                    [
                        new ProcessTemplateDecisionRule(
                            new ProcessTemplateRuleCondition('amount', 'gt', 10000),
                            'gf_approval'
                        ),
                        new ProcessTemplateDecisionRule(
                            null,
                            'department_approval',
                            true
                        ),
                    ]
                ),
            ]
        );
    }

    private function event(int $id, string $stepKey, int $minuteOffset): ProcessEventRecord
    {
        $time = (new DateTimeImmutable('2026-05-29T09:00:00+00:00'))->modify(sprintf('+%d minutes', $minuteOffset));

        return new ProcessEventRecord(
            $id,
            sprintf('evt-%d', $id),
            'test',
            'invoice',
            $stepKey,
            $stepKey,
            'doc-1',
            'uuid-1',
            1,
            'user-1',
            $time,
            $time,
            '{}',
            '{}',
            1
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function snapshot(string $externalEventKey, array $attributes): ContextSnapshot
    {
        return new ContextSnapshot(
            new DocumentRef('test', 'doc-1', 'uuid-1', 1),
            new DateTimeImmutable('2026-05-29T09:00:00+00:00'),
            $attributes,
            [],
            'invoice',
            $externalEventKey,
            1
        );
    }
}
