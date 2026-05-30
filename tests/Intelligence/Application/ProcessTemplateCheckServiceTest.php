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
        self::assertSame(['sent'], $result->expectedSteps);
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
        self::assertSame(['sent'], $result->expectedSteps);
        self::assertSame(['Parallel Group satisfied: booking_and_payment'], $result->parallelGroupMessages);
    }

    public function testRequiredStepOrderAllowsIntermediateSteps(): void
    {
        $service = new ProcessTemplateCheckService(
            new InMemoryDocumentTimelineProvider(
                [],
                [
                    $this->event(1, '01 Rechnungen pruefen', 0),
                    $this->event(2, '03 Freigabe_klein', 1),
                    $this->event(3, '05 Ausgangsrechnung buchen', 2),
                    $this->event(4, '07 Zahlungseingang erwartet', 3),
                    $this->event(5, '09 Rechnungen Abschluss', 4),
                ],
                [
                    $this->snapshot('evt-1', ['amount_net' => 83.0]),
                ]
            )
        );

        $result = $service->checkDocument(
            'uuid-1',
            'invoice',
            new ProcessTemplate(
                'invoice',
                steps: [
                    new ProcessTemplateStep('01 Rechnungen pruefen'),
                    new ProcessTemplateStep('03 Freigabe_klein'),
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
                        ['amount_net'],
                        [
                            new ProcessTemplateDecisionRule(
                                new ProcessTemplateRuleCondition('amount_net', 'gt', 50),
                                '03 Freigabe_klein'
                            ),
                            new ProcessTemplateDecisionRule(
                                null,
                                '05 Ausgangsrechnung buchen',
                                true
                            ),
                        ]
                    ),
                ],
                requiredStepKeys: ['01 Rechnungen pruefen', '09 Rechnungen Abschluss']
            ),
            1
        );

        self::assertTrue($result->isOk());
        self::assertSame(['01 Rechnungen pruefen', '09 Rechnungen Abschluss'], $result->expectedSteps);
        self::assertNotContains('Wrong order', $result->deviations);
        self::assertSame(['Parallel Group satisfied: buchen_und_zahlung'], $result->parallelGroupMessages);
    }

    public function testRequiredStepOrderStillReportsRealWrongOrder(): void
    {
        $service = new ProcessTemplateCheckService(
            new InMemoryDocumentTimelineProvider(
                [],
                [
                    $this->event(1, '09 Rechnungen Abschluss', 0),
                    $this->event(2, '01 Rechnungen pruefen', 1),
                ]
            )
        );

        $result = $service->checkDocument(
            'uuid-1',
            'invoice',
            new ProcessTemplate(
                'invoice',
                steps: [
                    new ProcessTemplateStep('01 Rechnungen pruefen'),
                    new ProcessTemplateStep('09 Rechnungen Abschluss'),
                ],
                requiredStepKeys: ['01 Rechnungen pruefen', '09 Rechnungen Abschluss']
            ),
            1
        );

        self::assertContains('Wrong order', $result->deviations);
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
            'Decision rule violation: approval_route after invoice_checked expected gf_approval but got department_approval. Context: amount=12000. Rule: when amount gt 10000',
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

    public function testCheckDocumentSkipsFollowUpDecisionPointOutsideSelectedPath(): void
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
            $this->templateWithFollowUpDecisionPoint(),
            1
        );

        self::assertTrue($result->isOk());
        self::assertSame([], $result->deviations);
    }

    public function testCheckDocumentReportsMissingFollowUpDecisionPointWhenPreviouslyExpected(): void
    {
        $service = new ProcessTemplateCheckService(
            new InMemoryDocumentTimelineProvider(
                [],
                [
                    $this->event(1, 'invoice_checked', 0),
                    $this->event(2, 'invoice_finished', 1),
                ],
                [
                    $this->snapshot('evt-1', ['amount' => 12000]),
                ]
            )
        );

        $result = $service->checkDocument(
            'uuid-1',
            'invoice',
            $this->templateWithFollowUpDecisionPoint(),
            1
        );

        self::assertContains(
            'Decision rule violation: follow_up_approval after step small_approval not found',
            $result->deviations
        );
    }

    public function testCheckDocumentAcceptsDecisionExpectedStepWhenActualNextStepIsInSatisfiedAnyOrderParallelGroup(): void
    {
        $service = new ProcessTemplateCheckService(
            new InMemoryDocumentTimelineProvider(
                [],
                [
                    $this->event(1, 'invoice_checked', 0),
                    $this->event(2, 'payment_expected', 1),
                    $this->event(3, 'booked', 2),
                    $this->event(4, 'invoice_finished', 3),
                ],
                [
                    $this->snapshot('evt-1', ['amount' => 100]),
                ]
            )
        );

        $result = $service->checkDocument(
            'uuid-1',
            'invoice',
            $this->templateWithDecisionParallelGroup('booked'),
            1
        );

        self::assertTrue($result->isOk());
        self::assertSame([], $result->deviations);
        self::assertSame(['Parallel Group satisfied: booking_and_payment'], $result->parallelGroupMessages);
    }

    public function testCheckDocumentReportsDecisionViolationWhenActualNextStepIsNotInExpectedParallelGroup(): void
    {
        $service = new ProcessTemplateCheckService(
            new InMemoryDocumentTimelineProvider(
                [],
                [
                    $this->event(1, 'invoice_checked', 0),
                    $this->event(2, 'manual_review', 1),
                    $this->event(3, 'booked', 2),
                    $this->event(4, 'payment_expected', 3),
                ],
                [
                    $this->snapshot('evt-1', ['amount' => 100]),
                ]
            )
        );

        $result = $service->checkDocument(
            'uuid-1',
            'invoice',
            $this->templateWithDecisionParallelGroup('booked'),
            1
        );

        self::assertContains(
            'Decision rule violation: booking_route after invoice_checked expected booked but got manual_review. Context: amount=100. Rule: else',
            $result->deviations
        );
    }

    public function testCheckDocumentReportsDecisionViolationWhenParallelGroupIsIncomplete(): void
    {
        $service = new ProcessTemplateCheckService(
            new InMemoryDocumentTimelineProvider(
                [],
                [
                    $this->event(1, 'invoice_checked', 0),
                    $this->event(2, 'payment_expected', 1),
                    $this->event(3, 'invoice_finished', 2),
                ],
                [
                    $this->snapshot('evt-1', ['amount' => 100]),
                ]
            )
        );

        $result = $service->checkDocument(
            'uuid-1',
            'invoice',
            $this->templateWithDecisionParallelGroup('booked'),
            1
        );

        self::assertContains(
            'Decision rule violation: booking_route after invoice_checked expected booked but got payment_expected. Context: amount=100. Rule: else',
            $result->deviations
        );
        self::assertContains(
            'Parallel Group incomplete: booking_and_payment (missing: booked)',
            $result->deviations
        );
    }

    public function testCheckDocumentDecisionViolationContainsRequiredContextValuesAndMatchedRule(): void
    {
        $service = new ProcessTemplateCheckService(
            new InMemoryDocumentTimelineProvider(
                [],
                [
                    $this->event(1, 'invoice_checked', 0),
                    $this->event(2, 'payment_expected', 1),
                ],
                [
                    $this->snapshot('evt-1', [
                        'invoice_direction' => 'RE - Eingang',
                        'amount_net' => 83.0,
                    ]),
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
                    new ProcessTemplateStep('booked'),
                    new ProcessTemplateStep('payment_expected'),
                ],
                decisionPoints: [
                    new ProcessTemplateDecisionPoint(
                        'route_after_pruefung',
                        'invoice_checked',
                        ['invoice_direction', 'amount_net'],
                        [
                            new ProcessTemplateDecisionRule(
                                new ProcessTemplateRuleCondition('invoice_direction', 'eq', 'RE - Ausgang'),
                                'sent'
                            ),
                            new ProcessTemplateDecisionRule(
                                new ProcessTemplateRuleCondition('amount_net', 'gt', 1000),
                                'large_approval'
                            ),
                            new ProcessTemplateDecisionRule(
                                null,
                                'booked',
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
            'Decision rule violation: route_after_pruefung after invoice_checked expected booked but got payment_expected. Context: invoice_direction="RE - Eingang", amount_net=83.0. Rule: else',
            $result->deviations
        );
    }

    public function testCheckDocumentUsesFallbackSnapshotWhenAfterStepHasNoContext(): void
    {
        $service = new ProcessTemplateCheckService(
            new InMemoryDocumentTimelineProvider(
                [],
                [
                    $this->event(1, 'invoice_checked', 0),
                    $this->event(2, 'department_approval', 1),
                ],
                [
                    $this->snapshot('evt-2', ['amount' => 5000]),
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

    public function testCheckDocumentKeepsContextFromDuplicateAfterStepEvent(): void
    {
        $service = new ProcessTemplateCheckService(
            new InMemoryDocumentTimelineProvider(
                [],
                [
                    $this->event(1, 'invoice_checked', 0),
                    $this->event(2, 'invoice_checked', 1),
                    $this->event(3, 'gf_approval', 2),
                ],
                [
                    $this->snapshot('evt-2', ['amount' => 12000]),
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
                    $this->snapshot('evt-1', ['invoice_direction' => [], 'amount_net' => null]),
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

    private function templateWithDecisionParallelGroup(string $expectedNextStepKey): ProcessTemplate
    {
        return new ProcessTemplate(
            'invoice',
            steps: [
                new ProcessTemplateStep('invoice_checked'),
                new ProcessTemplateStep('manual_review'),
                new ProcessTemplateStep('booked'),
                new ProcessTemplateStep('payment_expected'),
                new ProcessTemplateStep('invoice_finished'),
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
                    'booking_route',
                    'invoice_checked',
                    ['amount'],
                    [
                        new ProcessTemplateDecisionRule(
                            null,
                            $expectedNextStepKey,
                            true
                        ),
                    ]
                ),
            ],
            requiredStepKeys: ['invoice_checked', 'invoice_finished']
        );
    }

    private function templateWithFollowUpDecisionPoint(): ProcessTemplate
    {
        return new ProcessTemplate(
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
                            new ProcessTemplateRuleCondition('amount', 'gt', 10000),
                            'small_approval'
                        ),
                        new ProcessTemplateDecisionRule(
                            null,
                            'invoice_finished',
                            true
                        ),
                    ]
                ),
                new ProcessTemplateDecisionPoint(
                    'follow_up_approval',
                    'small_approval',
                    ['amount'],
                    [
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
