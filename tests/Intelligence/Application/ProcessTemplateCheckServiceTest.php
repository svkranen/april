<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\DocumentTimelineEventRow;
use App\Intelligence\Application\DocumentTimelineProvider;
use App\Intelligence\Application\DocumentTimelineReport;
use App\Intelligence\Application\EventTimelineOrder;
use App\Intelligence\Application\ProcessTemplateCheckService;
use App\Intelligence\Domain\ContextSnapshot;
use App\Intelligence\Domain\DocumentRef;
use App\Intelligence\Domain\ProcessEventRecord;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateContextPolicy;
use App\Intelligence\Domain\ProcessTemplateDecisionPoint;
use App\Intelligence\Domain\ProcessTemplateDecisionRule;
use App\Intelligence\Domain\ProcessTemplateFieldMapping;
use App\Intelligence\Domain\ProcessTemplateParallelGroup;
use App\Intelligence\Domain\ProcessTemplateSignCheck;
use App\Intelligence\Domain\ProcessTemplateStep;
use App\Intelligence\Domain\ProcessTemplateRuleCondition;
use App\Intelligence\Domain\ProcessTemplateTransition;
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

    public function testCompleteParallelGroupReportsMissingConfiguredNextStep(): void
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
                    new ProcessTemplateStep('invoice_finished'),
                ],
                parallelGroups: [
                    new ProcessTemplateParallelGroup(
                        'booking_and_payment',
                        'sent',
                        ['booked', 'payment_expected'],
                        'any',
                        'invoice_finished'
                    ),
                ]
            ),
            1
        );

        self::assertContains('Missing step: invoice_finished', $result->deviations);
        self::assertContains('Missing next after parallel group: booking_and_payment -> invoice_finished', $result->deviations);
        self::assertSame(['Parallel Group satisfied: booking_and_payment (next: invoice_finished)'], $result->parallelGroupMessages);
    }

    public function testExplicitStepTransitionRejectsUnexpectedNextStep(): void
    {
        $service = new ProcessTemplateCheckService(
            new InMemoryDocumentTimelineProvider(
                [],
                [
                    $this->event(1, 'sent', 0),
                    $this->event(2, 'manual_review', 1),
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
                    new ProcessTemplateStep('manual_review'),
                ],
                transitions: [
                    new ProcessTemplateTransition('sent', 'booked'),
                ]
            ),
            1
        );

        self::assertContains('Transition violation: sent expected one of booked but got manual_review', $result->deviations);
    }

    public function testTransitionToParallelGroupActivatesRequiredSteps(): void
    {
        $service = new ProcessTemplateCheckService(
            new InMemoryDocumentTimelineProvider(
                [],
                [
                    $this->event(1, 'sent', 0),
                    $this->event(2, 'booked', 1),
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
                transitions: [
                    new ProcessTemplateTransition('sent', toParallelGroup: 'booking_and_payment'),
                ],
                parallelGroups: [
                    new ProcessTemplateParallelGroup(
                        'booking_and_payment',
                        null,
                        ['booked', 'payment_expected'],
                        'any'
                    ),
                ]
            ),
            1
        );

        self::assertContains('Parallel Group incomplete: booking_and_payment (missing: payment_expected)', $result->deviations);
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
                fieldMappings: $this->immutableFieldMappings(['amount']),
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
                fieldMappings: $this->immutableFieldMappings(['amount_net']),
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

    public function testCheckDocumentActivatesParallelGroupFromDecisionRuleTarget(): void
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
            $this->templateWithDecisionExpectedParallelGroup(),
            1
        );

        self::assertTrue($result->isOk());
        self::assertSame([], $result->deviations);
        self::assertSame(['Parallel Group satisfied: booking_and_payment (next: invoice_finished)'], $result->parallelGroupMessages);
    }

    public function testCheckDocumentReportsIncompleteParallelGroupFromDecisionRuleTarget(): void
    {
        $service = new ProcessTemplateCheckService(
            new InMemoryDocumentTimelineProvider(
                [],
                [
                    $this->event(1, 'invoice_checked', 0),
                ],
                [
                    $this->snapshot('evt-1', ['amount' => 100]),
                ]
            )
        );

        $result = $service->checkDocument(
            'uuid-1',
            'invoice',
            $this->templateWithDecisionExpectedParallelGroup(),
            1
        );

        self::assertContains(
            'Decision rule violation: booking_route after invoice_checked expected parallel group booking_and_payment but got none. Context: amount=100. Rule: else',
            $result->deviations
        );
        self::assertContains(
            'Parallel Group incomplete: booking_and_payment (missing: booked, payment_expected)',
            $result->deviations
        );
    }

    public function testCheckDocumentReportsDecisionViolationWhenActualNextStepIsOutsideExpectedParallelGroupTarget(): void
    {
        $service = new ProcessTemplateCheckService(
            new InMemoryDocumentTimelineProvider(
                [],
                [
                    $this->event(1, 'invoice_checked', 0),
                    $this->event(2, 'manual_review', 1),
                ],
                [
                    $this->snapshot('evt-1', ['amount' => 100]),
                ]
            )
        );

        $result = $service->checkDocument(
            'uuid-1',
            'invoice',
            $this->templateWithDecisionExpectedParallelGroup(),
            1
        );

        self::assertContains(
            'Decision rule violation: booking_route after invoice_checked expected parallel group booking_and_payment but got manual_review. Context: amount=100. Rule: else',
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
                fieldMappings: $this->immutableFieldMappings(['invoice_direction', 'amount_net']),
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
                fieldMappings: $this->immutableFieldMappings(['invoice_direction', 'amount_net']),
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

    public function testCheckDocumentFailsWhenDecisionFieldStabilityIsMissing(): void
    {
        $service = new ProcessTemplateCheckService(
            new InMemoryDocumentTimelineProvider(
                [],
                [
                    $this->event(1, 'invoice_checked', 0),
                ]
            )
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing stability for decision field "amount" in decision point "approval_route".');

        $service->checkDocument(
            'uuid-1',
            'invoice',
            new ProcessTemplate(
                'invoice',
                steps: [
                    new ProcessTemplateStep('invoice_checked'),
                    new ProcessTemplateStep('department_approval'),
                ],
                fieldMappings: [
                    'amount' => new ProcessTemplateFieldMapping('amount', 'test'),
                ],
                decisionPoints: [
                    new ProcessTemplateDecisionPoint(
                        'approval_route',
                        'invoice_checked',
                        ['amount'],
                        [
                            new ProcessTemplateDecisionRule(null, 'department_approval', true),
                        ]
                    ),
                ]
            ),
            1
        );
    }

    public function testSnapshotRequiredContextLoadedAfter60SecondsIsUsedForDecision(): void
    {
        $service = new ProcessTemplateCheckService(
            new InMemoryDocumentTimelineProvider(
                [],
                [
                    $this->event(1, 'invoice_checked', 0),
                    $this->event(2, 'gf_approval', 1),
                ],
                [
                    $this->snapshotForEvent('evt-1', ['amount' => 12000], 0, 60, true),
                ]
            )
        );

        $result = $service->checkDocument(
            'uuid-1',
            'invoice',
            $this->templateWithSingleDecisionField(
                'amount',
                ProcessTemplateFieldMapping::STABILITY_SNAPSHOT_REQUIRED,
                'gf_approval',
                300
            ),
            1
        );

        self::assertSame('OK', $result->status());
        self::assertSame([], $result->deviations);
        self::assertSame([], $result->contextIssues);
    }

    public function testSnapshotRequiredContextLoadedAfter301SecondsIsUncertainAndDoesNotCreateDecisionDeviation(): void
    {
        $service = new ProcessTemplateCheckService(
            new InMemoryDocumentTimelineProvider(
                [],
                [
                    $this->event(1, 'invoice_checked', 0),
                    $this->event(2, 'department_approval', 1),
                ],
                [
                    $this->snapshotForEvent('evt-1', ['amount' => 12000], 0, 301, false),
                ]
            )
        );

        $result = $service->checkDocument(
            'uuid-1',
            'invoice',
            $this->templateWithSingleDecisionField(
                'amount',
                ProcessTemplateFieldMapping::STABILITY_SNAPSHOT_REQUIRED,
                'department_approval',
                300
            ),
            1
        );

        self::assertSame('UNCERTAIN_CONTEXT_STALE', $result->status());
        self::assertSame([], $result->deviations);
        self::assertSame([
            'Uncertain context stale: decision point approval_route field amount snapshot freshness_seconds=301 exceeds max_delay_seconds=300. event occurred_at=2026-05-29T09:00:00+00:00 loaded_at=2026-05-29T09:05:01+00:00',
        ], $result->contextIssues);
    }

    public function testMutableDecisionFieldWithoutSnapshotIsUncheckable(): void
    {
        $service = new ProcessTemplateCheckService(
            new InMemoryDocumentTimelineProvider(
                [],
                [
                    $this->event(1, 'invoice_checked', 0),
                    $this->event(2, 'department_approval', 1),
                ]
            )
        );

        $result = $service->checkDocument(
            'uuid-1',
            'invoice',
            $this->templateWithSingleDecisionField(
                'amount',
                ProcessTemplateFieldMapping::STABILITY_MUTABLE,
                'department_approval',
                300
            ),
            1
        );

        self::assertSame('UNCHECKABLE_CONTEXT_MISSING', $result->status());
        self::assertSame([], $result->deviations);
        self::assertSame([
            'Uncheckable context missing: decision point approval_route field amount requires a snapshot. event occurred_at=2026-05-29T09:00:00+00:00',
        ], $result->contextIssues);
    }

    public function testNegativeFreshnessIsTimeSkewAndDoesNotCreateDecisionDeviation(): void
    {
        $service = new ProcessTemplateCheckService(
            new InMemoryDocumentTimelineProvider(
                [],
                [
                    $this->event(1, 'invoice_checked', 0),
                    $this->event(2, 'department_approval', 1),
                ],
                [
                    $this->snapshotForEvent('evt-1', ['amount' => 12000], 300, 0, false),
                ]
            )
        );

        $result = $service->checkDocument(
            'uuid-1',
            'invoice',
            $this->templateWithSingleDecisionField(
                'amount',
                ProcessTemplateFieldMapping::STABILITY_MUTABLE,
                'department_approval',
                300
            ),
            1
        );

        self::assertSame('UNCERTAIN_CONTEXT_TIME_SKEW', $result->status());
        self::assertSame([], $result->deviations);
        self::assertSame([
            'Uncertain context time skew: decision point approval_route field amount snapshot freshness_seconds=-300 is negative. event occurred_at=2026-05-29T09:05:00+00:00 loaded_at=2026-05-29T09:00:00+00:00',
        ], $result->contextIssues);
    }

    public function testDecisionCheckRecalculatesFreshnessFromSnapshotTimestampsInsteadOfTrustingStoredValue(): void
    {
        $eventOccurredAt = new DateTimeImmutable('2026-05-31T05:08:00+00:00');
        $loadedAt = new DateTimeImmutable('2026-05-31T05:11:44+00:00');
        $service = new ProcessTemplateCheckService(
            new class($eventOccurredAt, $loadedAt) implements DocumentTimelineProvider {
                public function __construct(
                    private readonly DateTimeImmutable $eventOccurredAt,
                    private readonly DateTimeImmutable $loadedAt
                ) {
                }

                public function build(string $documentUuid, EventTimelineOrder $order = EventTimelineOrder::DEFAULT): DocumentTimelineReport
                {
                    return new DocumentTimelineReport(
                        $documentUuid,
                        [],
                        [
                            new DocumentTimelineEventRow(
                                'evt-1',
                                'invoice_checked',
                                'invoice_checked',
                                'invoice',
                                1,
                                $this->eventOccurredAt,
                                $this->eventOccurredAt,
                                1,
                                1,
                                [
                                    'attributes' => ['amount' => 12000],
                                    'fields' => ['amount'],
                                    'occurred_at' => $this->eventOccurredAt->format(DATE_ATOM),
                                    'loaded_at' => $this->loadedAt->format(DATE_ATOM),
                                    'freshness_seconds' => -6976,
                                    'is_fresh_for_decision_check' => false,
                                    'source' => 'snapshot',
                                ]
                            ),
                            new DocumentTimelineEventRow(
                                'evt-2',
                                'gf_approval',
                                'gf_approval',
                                'invoice',
                                1,
                                $this->loadedAt,
                                $this->loadedAt,
                                2,
                                1
                            ),
                        ]
                    );
                }
            }
        );

        $result = $service->checkDocument(
            'uuid-1',
            'invoice',
            $this->templateWithSingleDecisionField(
                'amount',
                ProcessTemplateFieldMapping::STABILITY_SNAPSHOT_REQUIRED,
                'gf_approval',
                300
            ),
            1
        );

        self::assertSame('OK', $result->status());
        self::assertSame([], $result->contextIssues);
        self::assertSame([], $result->deviations);
    }

    public function testSignCheckUsesContextSnapshotOnlyAndIgnoresRepeatedEvents(): void
    {
        $service = new ProcessTemplateCheckService(
            new InMemoryDocumentTimelineProvider(
                [],
                [
                    $this->event(1, 'approval', 0),
                    $this->event(2, 'approval', 1),
                    $this->event(3, 'approval', 2),
                ],
                [
                    $this->snapshot('evt-1', ['ToBeSignedBy' => ['A', 'B', 'C'], 'SignedBy' => ['A', 'C']]),
                    $this->snapshot('evt-2', ['ToBeSignedBy' => ['A', 'B', 'C'], 'SignedBy' => ['A', 'C']]),
                    $this->snapshot('evt-3', ['ToBeSignedBy' => ['A', 'B', 'C'], 'SignedBy' => ['A', 'C']]),
                ]
            )
        );

        $result = $service->checkDocument('uuid-1', 'invoice', $this->templateWithSignCheck(), 1);

        self::assertSame('DEVIATION', $result->status());
        self::assertCount(1, $result->signCheckResults);
        self::assertSame('partial', $result->signCheckResults[0]->status);
        self::assertSame(3, $result->signCheckResults[0]->requiredCount);
        self::assertSame(2, $result->signCheckResults[0]->actualCount);
        self::assertSame(1, $result->signCheckResults[0]->missingCount);
    }

    public function testSatisfiedSignCheckIsOk(): void
    {
        $service = new ProcessTemplateCheckService(
            new InMemoryDocumentTimelineProvider(
                [],
                [$this->event(1, 'approval', 0)],
                [$this->snapshot('evt-1', ['ToBeSignedBy' => ['A', 'B'], 'SignedBy' => ['A', 'B']])]
            )
        );

        $result = $service->checkDocument('uuid-1', 'invoice', $this->templateWithSignCheck(), 1);

        self::assertSame('OK', $result->status());
        self::assertSame('satisfied', $result->signCheckResults[0]->status);
    }

    public function testSignCheckMissingContextIsDeviation(): void
    {
        $service = new ProcessTemplateCheckService(
            new InMemoryDocumentTimelineProvider(
                [],
                [$this->event(1, 'approval', 0)],
                [$this->snapshot('evt-1', ['ToBeSignedBy' => ['A', 'B']])]
            )
        );

        $result = $service->checkDocument('uuid-1', 'invoice', $this->templateWithSignCheck(), 1);

        self::assertSame('DEVIATION', $result->status());
        self::assertSame('missing_context', $result->signCheckResults[0]->status);
    }

    public function testSignCheckUsesLatestSnapshotRequiredSetWithoutHistoricalAggregation(): void
    {
        $service = new ProcessTemplateCheckService(
            new InMemoryDocumentTimelineProvider(
                [],
                [
                    $this->event(1, 'assign', 0),
                    $this->event(2, 'approval', 1),
                ],
                [
                    $this->snapshot('evt-1', ['ToBeSignedBy' => ['Mueller', 'Schneider'], 'SignedBy' => ['Schneider']]),
                    $this->snapshot('evt-2', ['ToBeSignedBy' => ['Schulze', 'Schneider'], 'SignedBy' => ['Schneider', 'Schulze']]),
                ]
            )
        );

        $result = $service->checkDocument('uuid-1', 'invoice', $this->templateWithSignCheck(['assign', 'approval']), 1);

        self::assertSame('OK', $result->status());
        self::assertSame('satisfied', $result->signCheckResults[0]->status);
        self::assertSame(2, $result->signCheckResults[0]->requiredCount);
        self::assertSame(0, $result->signCheckResults[0]->missingCount);
        self::assertSame([], $result->signCheckResults[0]->missingValues);
    }

    public function testPreviouslySatisfiedSignCheckCanBecomePartialWhenLatestSnapshotExpandsRequiredSet(): void
    {
        $service = new ProcessTemplateCheckService(
            new InMemoryDocumentTimelineProvider(
                [],
                [
                    $this->event(1, 'approval', 0),
                    $this->event(2, 'assign_more', 1),
                ],
                [
                    $this->snapshot('evt-1', ['ToBeSignedBy' => ['A', 'B'], 'SignedBy' => ['A', 'B']]),
                    $this->snapshot('evt-2', ['ToBeSignedBy' => ['A', 'B', 'C'], 'SignedBy' => ['A', 'B']]),
                ]
            )
        );

        $result = $service->checkDocument('uuid-1', 'invoice', $this->templateWithSignCheck(['approval', 'assign_more']), 1);

        self::assertSame('DEVIATION', $result->status());
        self::assertSame('partial', $result->signCheckResults[0]->status);
        self::assertSame(3, $result->signCheckResults[0]->requiredCount);
        self::assertSame(1, $result->signCheckResults[0]->missingCount);
        self::assertSame(['C'], $result->signCheckResults[0]->missingValues);
    }

    public function testLatestIncompleteSnapshotDoesNotReuseEarlierRequiredSet(): void
    {
        $service = new ProcessTemplateCheckService(
            new InMemoryDocumentTimelineProvider(
                [],
                [
                    $this->event(1, 'assign', 0),
                    $this->event(2, 'approval', 1),
                ],
                [
                    $this->snapshot('evt-1', ['ToBeSignedBy' => ['A', 'B'], 'SignedBy' => ['A']]),
                    $this->snapshot('evt-2', ['SignedBy' => ['A', 'B']]),
                ]
            )
        );

        $result = $service->checkDocument('uuid-1', 'invoice', $this->templateWithSignCheck(['assign', 'approval']), 1);

        self::assertSame('DEVIATION', $result->status());
        self::assertSame('missing_context', $result->signCheckResults[0]->status);
        self::assertSame(['ToBeSignedBy'], $result->signCheckResults[0]->missingContextFields);
    }

    public function testImmutableDecisionFieldMayUseLateSnapshot(): void
    {
        $service = new ProcessTemplateCheckService(
            new InMemoryDocumentTimelineProvider(
                [],
                [
                    $this->event(1, 'invoice_checked', 0),
                    $this->event(2, 'gf_approval', 1),
                ],
                [
                    $this->snapshotForEvent('evt-1', ['amount' => 12000], 0, 1200, false),
                ]
            )
        );

        $result = $service->checkDocument(
            'uuid-1',
            'invoice',
            $this->templateWithSingleDecisionField(
                'amount',
                ProcessTemplateFieldMapping::STABILITY_IMMUTABLE,
                'gf_approval',
                300
            ),
            1
        );

        self::assertSame('OK', $result->status());
        self::assertSame([], $result->deviations);
        self::assertSame([], $result->contextIssues);
    }

    private function templateWithDecisionPath(string $nextStepKey): ProcessTemplate
    {
        return new ProcessTemplate(
            'invoice',
            steps: [
                new ProcessTemplateStep('invoice_checked'),
                new ProcessTemplateStep($nextStepKey),
            ],
            fieldMappings: $this->immutableFieldMappings(['amount']),
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

    /**
     * @param array<int, string> $steps
     */
    private function templateWithSignCheck(array $steps = ['approval']): ProcessTemplate
    {
        return new ProcessTemplate(
            'invoice',
            steps: array_map(
                static fn (string $step): ProcessTemplateStep => new ProcessTemplateStep($step),
                $steps
            ),
            signChecks: [
                new ProcessTemplateSignCheck('bauleiter_freigabe', 'ToBeSignedBy', 'SignedBy'),
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
            fieldMappings: $this->immutableFieldMappings(['amount']),
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

    private function templateWithDecisionExpectedParallelGroup(): ProcessTemplate
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
                    'any',
                    'invoice_finished'
                ),
            ],
            fieldMappings: $this->immutableFieldMappings(['amount']),
            decisionPoints: [
                new ProcessTemplateDecisionPoint(
                    'booking_route',
                    'invoice_checked',
                    ['amount'],
                    [
                        new ProcessTemplateDecisionRule(
                            null,
                            null,
                            true,
                            'booking_and_payment'
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
            fieldMappings: $this->immutableFieldMappings(['amount']),
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

    private function templateWithSingleDecisionField(string $field, string $stability, string $actualNextStepKey, int $maxDelaySeconds): ProcessTemplate
    {
        return new ProcessTemplate(
            'invoice',
            steps: [
                new ProcessTemplateStep('invoice_checked'),
                new ProcessTemplateStep('department_approval'),
                new ProcessTemplateStep('gf_approval'),
            ],
            fieldMappings: [
                $field => new ProcessTemplateFieldMapping(
                    $field,
                    'test',
                    stability: $stability
                ),
            ],
            decisionPoints: [
                new ProcessTemplateDecisionPoint(
                    'approval_route',
                    'invoice_checked',
                    [$field],
                    [
                        new ProcessTemplateDecisionRule(
                            new ProcessTemplateRuleCondition($field, 'gt', 10000),
                            'gf_approval'
                        ),
                        new ProcessTemplateDecisionRule(null, $actualNextStepKey, true),
                    ]
                ),
            ],
            requiredStepKeys: ['invoice_checked'],
            contextPolicy: new ProcessTemplateContextPolicy($maxDelaySeconds, 'uncertain')
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
     * @param array<int, string> $fields
     * @return array<string, ProcessTemplateFieldMapping>
     */
    private function immutableFieldMappings(array $fields): array
    {
        $mappings = [];
        foreach ($fields as $field) {
            $mappings[$field] = new ProcessTemplateFieldMapping(
                $field,
                'test',
                stability: ProcessTemplateFieldMapping::STABILITY_IMMUTABLE
            );
        }

        return $mappings;
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

    /**
     * @param array<string, mixed> $attributes
     */
    private function snapshotForEvent(string $externalEventKey, array $attributes, int $occurredSecondOffset, int $loadedSecondOffset, bool $isFresh): ContextSnapshot
    {
        $base = new DateTimeImmutable('2026-05-29T09:00:00+00:00');
        $occurredAt = $base->modify(sprintf('+%d seconds', $occurredSecondOffset));
        $loadedAt = $base->modify(sprintf('+%d seconds', $loadedSecondOffset));

        return new ContextSnapshot(
            new DocumentRef('test', 'doc-1', 'uuid-1', 1),
            $loadedAt,
            $attributes,
            [],
            'invoice',
            $externalEventKey,
            1,
            $occurredAt,
            $loadedAt,
            1,
            $loadedAt->getTimestamp() - $occurredAt->getTimestamp(),
            $isFresh
        );
    }
}
