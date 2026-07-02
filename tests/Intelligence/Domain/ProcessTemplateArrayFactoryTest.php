<?php

namespace App\Tests\Intelligence\Domain;

use App\Intelligence\Domain\ProcessTemplateArrayFactory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ProcessTemplateArrayFactoryTest extends TestCase
{
    public function testParsesSignChecks(): void
    {
        $template = ProcessTemplateArrayFactory::fromArray([
            'key' => 'invoice',
            'sign_checks' => [
                [
                    'key' => 'bauleiter_freigabe',
                    'label' => 'Freigabe durch alle vorgesehenen Bauleiter',
                    'required_set' => 'ToBeSignedBy',
                    'actual_set' => 'SignedBy',
                    'operator' => 'required_subset_of_actual',
                ],
            ],
        ]);

        self::assertCount(1, $template->signChecks);
        self::assertSame('bauleiter_freigabe', $template->signChecks[0]->key);
        self::assertSame('ToBeSignedBy', $template->signChecks[0]->requiredSetField);
        self::assertSame('SignedBy', $template->signChecks[0]->actualSetField);
    }

    public function testParsesCrossProcessRoutingRules(): void
    {
        $template = ProcessTemplateArrayFactory::fromArray([
            'key' => 'debitoren_intake',
            'cross_process_routing' => [
                [
                    'key' => 'route_to_aufmass',
                    'after_step' => '10 Intake abgeschlossen',
                    'when' => [
                        'document_type' => 'aufmass',
                    ],
                    'expected_process' => 'aufmass_workflow',
                ],
            ],
        ]);

        self::assertCount(1, $template->crossProcessRoutingRules);
        self::assertSame('route_to_aufmass', $template->crossProcessRoutingRules[0]->key);
        self::assertSame('10 Intake abgeschlossen', $template->crossProcessRoutingRules[0]->afterStep);
        self::assertSame(['document_type' => 'aufmass'], $template->crossProcessRoutingRules[0]->when);
        self::assertSame('aufmass_workflow', $template->crossProcessRoutingRules[0]->expectedProcess);
    }

    public function testParsesJourneyScopeAndProcessSteps(): void
    {
        $template = ProcessTemplateArrayFactory::fromArray([
            'key' => 'aufmass_verarbeitung',
            'version' => '1.0',
            'scope' => 'journey',
            'steps' => [
                [
                    'key' => 'import',
                    'type' => 'process',
                    'process_key' => 'generic_document_import',
                    'required' => true,
                    'when' => [
                        'amagno_known' => false,
                    ],
                ],
                [
                    'key' => 'export',
                    'type' => 'process',
                    'processKey' => 'nevaris_export',
                    'required' => 'false',
                    'when' => [
                        'document_type' => 'aufmass',
                    ],
                ],
            ],
        ]);

        self::assertSame('journey', $template->scope);
        self::assertCount(2, $template->steps);
        self::assertSame('process', $template->steps[0]->type);
        self::assertSame('generic_document_import', $template->steps[0]->processKey);
        self::assertTrue($template->steps[0]->required);
        self::assertSame(['amagno_known' => false], $template->steps[0]->when);
        self::assertSame('nevaris_export', $template->steps[1]->processKey);
        self::assertFalse($template->steps[1]->required);
        self::assertSame(['document_type' => 'aufmass'], $template->steps[1]->when);
    }

    public function testTemplateWithoutScopeStaysProcessTemplateCompatible(): void
    {
        $template = ProcessTemplateArrayFactory::fromArray([
            'key' => 'invoice',
            'steps' => [
                ['key' => 'received'],
                ['key' => 'started', 'type' => 'start'],
            ],
        ]);

        self::assertSame('process', $template->scope);
        self::assertSame('normal', $template->steps[0]->type);
        self::assertSame('start', $template->steps[1]->type);
        self::assertNull($template->steps[0]->processKey);
        self::assertTrue($template->steps[0]->required);
        self::assertSame([], $template->steps[0]->when);
    }

    public function testParsesTypedChecksSignCheck(): void
    {
        $template = ProcessTemplateArrayFactory::fromArray([
            'key' => 'invoice',
            'checks' => [
                [
                    'key' => 'bauleiter_freigabe',
                    'type' => 'sign_check',
                    'required_set' => ['from_context' => 'ToBeSignedBy'],
                    'actual_set' => ['from_context' => 'SignedBy'],
                    'operator' => 'required_subset_of_actual',
                ],
            ],
        ]);

        self::assertCount(1, $template->signChecks);
        self::assertSame('ToBeSignedBy', $template->signChecks[0]->requiredSetField);
        self::assertSame('SignedBy', $template->signChecks[0]->actualSetField);
    }

    public function testBuildsTemplateWithSteps(): void
    {
        $template = ProcessTemplateArrayFactory::fromArray([
            'key' => 'invoice',
            'version' => '1',
            'name' => 'Invoice Process',
            'initial_step' => 'received',
            'steps' => [
                ['key' => 'received', 'name' => 'Received', 'type' => 'start'],
                ['key' => 'approved'],
            ],
        ]);

        self::assertSame('invoice', $template->key);
        self::assertSame('1', $template->version);
        self::assertSame('Invoice Process', $template->name);
        self::assertSame('received', $template->initialStepKey);
        self::assertCount(2, $template->steps);
        self::assertSame('received', $template->steps[0]->key);
        self::assertSame('Received', $template->steps[0]->name);
        self::assertSame('start', $template->steps[0]->type);
        self::assertSame('approved', $template->steps[1]->key);
        self::assertNull($template->steps[1]->name);
        self::assertSame('normal', $template->steps[1]->type);
    }

    public function testBuildsTemplateWithTransitions(): void
    {
        $template = ProcessTemplateArrayFactory::fromArray([
            'key' => 'invoice',
            'steps' => [
                ['key' => 'received'],
                ['key' => 'approved'],
                ['key' => 'booked'],
            ],
            'transitions' => [
                ['from' => 'received', 'to' => 'approved'],
                ['from' => 'approved', 'to' => 'booked'],
            ],
        ]);

        self::assertCount(2, $template->transitions);
        self::assertSame('received', $template->transitions[0]->from);
        self::assertSame('approved', $template->transitions[0]->to);
        self::assertNull($template->transitions[0]->toParallelGroup);
        self::assertSame('approved', $template->transitions[1]->from);
        self::assertSame('booked', $template->transitions[1]->to);
    }

    public function testBuildsTransitionToParallelGroup(): void
    {
        $template = ProcessTemplateArrayFactory::fromArray([
            'key' => 'invoice',
            'steps' => [
                ['key' => 'approved'],
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
            'transitions' => [
                ['from' => 'approved', 'to_parallel_group' => 'booking_and_payment'],
            ],
        ]);

        self::assertCount(1, $template->transitions);
        self::assertSame('approved', $template->transitions[0]->from);
        self::assertNull($template->transitions[0]->to);
        self::assertSame('booking_and_payment', $template->transitions[0]->toParallelGroup);
    }

    public function testTransitionValidationRejectsUnknownTargetStep(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Transition from "received" references unknown target step "missing".');

        ProcessTemplateArrayFactory::fromArray([
            'key' => 'invoice',
            'steps' => [
                ['key' => 'received'],
            ],
            'transitions' => [
                ['from' => 'received', 'to' => 'missing'],
            ],
        ]);
    }

    public function testTransitionValidationRejectsUnknownSourceStep(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Transition from "missing" references unknown step.');

        ProcessTemplateArrayFactory::fromArray([
            'key' => 'invoice',
            'steps' => [
                ['key' => 'received'],
            ],
            'transitions' => [
                ['from' => 'missing', 'to' => 'received'],
            ],
        ]);
    }

    public function testTransitionValidationRejectsUnknownParallelGroup(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Transition from "received" references unknown parallel group "missing_group".');

        ProcessTemplateArrayFactory::fromArray([
            'key' => 'invoice',
            'steps' => [
                ['key' => 'received'],
            ],
            'transitions' => [
                ['from' => 'received', 'to_parallel_group' => 'missing_group'],
            ],
        ]);
    }

    public function testTransitionValidationRejectsAmbiguousTarget(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Transition from "received" must define exactly one of "to" or "to_parallel_group".');

        ProcessTemplateArrayFactory::fromArray([
            'key' => 'invoice',
            'steps' => [
                ['key' => 'received'],
                ['key' => 'approved'],
            ],
            'parallel_groups' => [
                [
                    'key' => 'approval_group',
                    'required_steps' => ['approved'],
                ],
            ],
            'transitions' => [
                ['from' => 'received', 'to' => 'approved', 'to_parallel_group' => 'approval_group'],
            ],
        ]);
    }

    public function testTransitionValidationRejectsRedundantDirectTargetIntoAnyOrderParallelGroup(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Transition from "sent" to "booked" is redundant because "sent" also activates any-order parallel group "booking_and_payment".');

        ProcessTemplateArrayFactory::fromArray([
            'key' => 'invoice',
            'steps' => [
                ['key' => 'sent'],
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
            'transitions' => [
                ['from' => 'sent', 'to' => 'booked'],
                ['from' => 'sent', 'to_parallel_group' => 'booking_and_payment'],
            ],
        ]);
    }

    public function testBuildsTemplateWithParallelGroups(): void
    {
        $template = ProcessTemplateArrayFactory::fromArray([
            'key' => 'invoice',
            'parallel_groups' => [
                [
                    'key' => 'approval_group',
                    'after' => 'received',
                    'required_steps' => ['manager_approval', 'finance_approval'],
                    'order' => 'any',
                    'next' => 'booked',
                ],
            ],
        ]);

        self::assertCount(1, $template->parallelGroups);
        self::assertSame('approval_group', $template->parallelGroups[0]->key);
        self::assertSame('received', $template->parallelGroups[0]->after);
        self::assertSame(['manager_approval', 'finance_approval'], $template->parallelGroups[0]->requiredStepKeys);
        self::assertSame('any', $template->parallelGroups[0]->order);
        self::assertSame('booked', $template->parallelGroups[0]->nextStepKey);
    }

    public function testUsesDefaultsForMissingOptionalFields(): void
    {
        $template = ProcessTemplateArrayFactory::fromArray([
            'key' => 'invoice',
        ]);

        self::assertSame('invoice', $template->key);
        self::assertSame('draft', $template->version);
        self::assertNull($template->name);
        self::assertNull($template->initialStepKey);
        self::assertSame([], $template->steps);
        self::assertSame([], $template->transitions);
        self::assertSame([], $template->parallelGroups);
        self::assertSame([], $template->contextProfileRequiredFields);
        self::assertSame([], $template->fieldMappings);
        self::assertSame([], $template->decisionPoints);
        self::assertSame([], $template->requiredStepKeys);
        self::assertNull($template->connector);
        self::assertSame('amagno', $template->sourceSystem);
        self::assertSame([], $template->accessProbes);
        self::assertSame([], $template->visibilityProfiles);
        self::assertSame([], $template->visibilityProfileResolvers);
        self::assertSame([], $template->visibilityRetryPolicies);
        self::assertSame([], $template->manualAccessTests);
    }

    public function testParsesAccessVisibilityMetadataWithSourceSystemDefaultsAndOverrides(): void
    {
        $template = ProcessTemplateArrayFactory::fromArray([
            'key' => 'invoice',
            'source_system' => 'amagno',
            'access_probes' => [
                'approval_location_a_today' => [
                    'type' => 'amagno_magnet_documents',
                    'magnet_id' => 1001,
                    'max_documents' => 500,
                    'description' => 'Freigabe Standort A',
                ],
                'external_today' => [
                    'source_system' => 'external_dms',
                    'type' => 'external_visibility_sample',
                    'query_id' => 'external-today',
                ],
            ],
            'visibility_check_profiles' => [
                'approval_location_a' => [
                    'expected_visible_in_probes' => ['approval_location_a_today'],
                    'expected_not_visible_in_probes' => ['external_today'],
                ],
            ],
            'visibility_profile_resolvers' => [
                'approval_location_by_context' => [
                    'field' => 'standort',
                    'map' => [
                        'A' => 'approval_location_a',
                    ],
                ],
            ],
            'visibility_retry_policies' => [
                'amagno_today_magnets' => [
                    'attempts_after_seconds' => [10, 30, 60],
                    'forbidden_found' => 'violation',
                    'expected_missing_after_last_attempt' => 'warning',
                    'probe_too_large' => 'technical_warning',
                ],
            ],
            'manual_access_tests' => [
                [
                    'key' => 'approver_scope_test',
                    'title' => 'Freigeberbezogene Sichtbarkeit',
                    'description' => 'Freigeber sehen nur eigene Dokumente.',
                    'test_procedure' => ['Benutzer A pruefen.', 'Benutzer B pruefen.'],
                    'expected_result' => ['A sieht das Dokument.', 'B sieht es nicht.'],
                    'frequency' => 'quartalsweise',
                ],
            ],
            'steps' => [
                [
                    'key' => 'received',
                    'after' => [
                        'visibility_checks' => [
                            [
                                'key' => 'route_to_location_approval',
                                'expected_profile_resolver' => 'approval_location_by_context',
                                'retry_policy' => 'amagno_today_magnets',
                                'source_system' => 'amagno',
                            ],
                        ],
                    ],
                    'before' => [
                        'visibility_checks' => [
                            [
                                'key' => 'initial_visibility',
                                'expected_profile' => 'approval_location_a',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        self::assertSame('amagno', $template->sourceSystem);

        self::assertCount(2, $template->accessProbes);
        self::assertSame('amagno', $template->accessProbes['approval_location_a_today']->sourceSystem);
        self::assertSame('amagno_magnet_documents', $template->accessProbes['approval_location_a_today']->type);
        self::assertSame(['magnet_id' => 1001], $template->accessProbes['approval_location_a_today']->options);
        self::assertSame(500, $template->accessProbes['approval_location_a_today']->maxDocuments);
        self::assertSame('external_dms', $template->accessProbes['external_today']->sourceSystem);
        self::assertSame(['query_id' => 'external-today'], $template->accessProbes['external_today']->options);

        self::assertSame(['approval_location_a_today'], $template->visibilityProfiles['approval_location_a']->expectedVisibleInProbeKeys);
        self::assertSame(['external_today'], $template->visibilityProfiles['approval_location_a']->expectedNotVisibleInProbeKeys);
        self::assertSame('standort', $template->visibilityProfileResolvers['approval_location_by_context']->field);
        self::assertSame(['A' => 'approval_location_a'], $template->visibilityProfileResolvers['approval_location_by_context']->map);
        self::assertSame([10, 30, 60], $template->visibilityRetryPolicies['amagno_today_magnets']->attemptsAfterSeconds);
        self::assertSame('technical_warning', $template->visibilityRetryPolicies['amagno_today_magnets']->probeTooLarge);

        self::assertCount(1, $template->manualAccessTests);
        self::assertSame('approver_scope_test', $template->manualAccessTests[0]->key);
        self::assertSame(['Benutzer A pruefen.', 'Benutzer B pruefen.'], $template->manualAccessTests[0]->testProcedure);

        self::assertCount(1, $template->steps[0]->beforeVisibilityChecks);
        self::assertSame('before', $template->steps[0]->beforeVisibilityChecks[0]->phase);
        self::assertSame('initial_visibility', $template->steps[0]->beforeVisibilityChecks[0]->key);
        self::assertSame('approval_location_a', $template->steps[0]->beforeVisibilityChecks[0]->expectedProfileKey);
        self::assertNull($template->steps[0]->beforeVisibilityChecks[0]->sourceSystemOverride);

        self::assertCount(1, $template->steps[0]->afterVisibilityChecks);
        self::assertSame('after', $template->steps[0]->afterVisibilityChecks[0]->phase);
        self::assertSame('route_to_location_approval', $template->steps[0]->afterVisibilityChecks[0]->key);
        self::assertSame('approval_location_by_context', $template->steps[0]->afterVisibilityChecks[0]->expectedProfileResolverKey);
        self::assertSame('amagno', $template->steps[0]->afterVisibilityChecks[0]->sourceSystemOverride);
    }

    public function testAcceptsCamelCaseSourceSystemAlias(): void
    {
        $template = ProcessTemplateArrayFactory::fromArray([
            'key' => 'invoice',
            'sourceSystem' => 'external_dms',
            'access_probes' => [
                'external_probe' => [
                    'type' => 'external_probe',
                ],
            ],
        ]);

        self::assertSame('external_dms', $template->sourceSystem);
        self::assertSame('external_dms', $template->accessProbes['external_probe']->sourceSystem);
    }

    public function testBuildsTemplateWithConnector(): void
    {
        $template = ProcessTemplateArrayFactory::fromArray([
            'key' => 'invoice',
            'connector' => [
                'type' => 'amagno',
                'connection' => 'default',
            ],
        ]);

        self::assertNotNull($template->connector);
        self::assertSame('amagno', $template->connector->type);
        self::assertSame('default', $template->connector->connection);
    }

    public function testBuildsTemplateWithRequiredSteps(): void
    {
        $template = ProcessTemplateArrayFactory::fromArray([
            'key' => 'invoice',
            'steps' => [
                ['key' => 'received'],
                ['key' => 'conditional_approval'],
                ['key' => 'archived'],
            ],
            'required_steps' => [
                'received',
                'archived',
            ],
        ]);

        self::assertSame(['received', 'archived'], $template->requiredStepKeys);
    }

    public function testBuildsTemplateWithFieldMappings(): void
    {
        $template = ProcessTemplateArrayFactory::fromArray([
            'key' => 'invoice',
            'field_mapping' => [
                'invoice_direction' => [
                    'source' => 'amagno',
                    'tag_name' => 'Eingang/Ausgang',
                ],
                'amount_net' => [
                    'source' => 'amagno',
                    'tag_name' => 'Nettobetrag',
                    'value_type' => 'number',
                    'stability' => 'immutable',
                ],
                'project_id' => [
                    'source' => 'amagno',
                    'tag_id' => 'tag-project-id',
                ],
            ],
        ]);

        self::assertCount(3, $template->fieldMappings);

        self::assertSame('invoice_direction', $template->fieldMappings['invoice_direction']->fieldKey);
        self::assertSame('amagno', $template->fieldMappings['invoice_direction']->source);
        self::assertSame('Eingang/Ausgang', $template->fieldMappings['invoice_direction']->tagName);
        self::assertNull($template->fieldMappings['invoice_direction']->tagId);
        self::assertNull($template->fieldMappings['invoice_direction']->valueType);

        self::assertSame('amount_net', $template->fieldMappings['amount_net']->fieldKey);
        self::assertSame('Nettobetrag', $template->fieldMappings['amount_net']->tagName);
        self::assertSame('number', $template->fieldMappings['amount_net']->valueType);
        self::assertSame('immutable', $template->fieldMappings['amount_net']->stability);

        self::assertSame('tag-project-id', $template->fieldMappings['project_id']->tagId);
    }

    public function testBuildsContextSnapshotPolicy(): void
    {
        $template = ProcessTemplateArrayFactory::fromArray([
            'key' => 'invoice',
            'context_policy' => [
                'snapshot' => [
                    'max_delay_seconds' => 300,
                    'stale_behavior' => 'uncertain',
                ],
            ],
        ]);

        self::assertNotNull($template->contextPolicy);
        self::assertSame(300, $template->contextPolicy->snapshotMaxDelaySeconds);
        self::assertSame('uncertain', $template->contextPolicy->snapshotStaleBehavior);
    }

    public function testRejectsUnsupportedFieldStability(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported field stability "sometimes".');

        ProcessTemplateArrayFactory::fromArray([
            'key' => 'invoice',
            'field_mapping' => [
                'amount_net' => [
                    'source' => 'amagno',
                    'stability' => 'sometimes',
                ],
            ],
        ]);
    }

    public function testBuildsTemplateWithDecisionPoints(): void
    {
        $template = ProcessTemplateArrayFactory::fromArray([
            'key' => 'invoice',
            'decision_points' => [
                [
                    'key' => 'approval_route',
                    'after' => 'invoice_checked',
                    'required_fields' => ['amount'],
                    'rules' => [
                        [
                            'when' => [
                                'amount' => [
                                    'gt' => 10000,
                                ],
                            ],
                            'expect_next' => 'gf_approval',
                        ],
                        [
                            'else' => [
                                'expect_next' => 'department_approval',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        self::assertCount(1, $template->decisionPoints);
        $decisionPoint = $template->decisionPoints[0];
        self::assertSame('approval_route', $decisionPoint->key);
        self::assertSame('invoice_checked', $decisionPoint->after);
        self::assertSame(['amount'], $decisionPoint->requiredFields);
        self::assertCount(2, $decisionPoint->rules);

        $rule = $decisionPoint->rules[0];
        self::assertFalse($rule->isElse);
        self::assertSame('gf_approval', $rule->expectedNextStepKey);
        self::assertNotNull($rule->condition);
        self::assertSame('amount', $rule->condition->field);
        self::assertSame('gt', $rule->condition->operator);
        self::assertSame(10000, $rule->condition->value);

        $elseRule = $decisionPoint->rules[1];
        self::assertTrue($elseRule->isElse);
        self::assertNull($elseRule->condition);
        self::assertSame('department_approval', $elseRule->expectedNextStepKey);
    }

    public function testBuildsDecisionRuleWithExpectedParallelGroup(): void
    {
        $template = ProcessTemplateArrayFactory::fromArray([
            'key' => 'invoice',
            'steps' => [
                ['key' => 'invoice_checked'],
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
            'decision_points' => [
                [
                    'key' => 'booking_route',
                    'after' => 'invoice_checked',
                    'rules' => [
                        [
                            'else' => [
                                'expect_next_parallel_group' => 'booking_and_payment',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $rule = $template->decisionPoints[0]->rules[0];

        self::assertTrue($rule->isElse);
        self::assertNull($rule->expectedNextStepKey);
        self::assertSame('booking_and_payment', $rule->expectedNextParallelGroupKey);
    }

    public function testRejectsDecisionRuleWithUnknownExpectedParallelGroup(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('references unknown parallel group "missing_group"');

        ProcessTemplateArrayFactory::fromArray([
            'key' => 'invoice',
            'steps' => [
                ['key' => 'invoice_checked'],
            ],
            'decision_points' => [
                [
                    'key' => 'booking_route',
                    'after' => 'invoice_checked',
                    'rules' => [
                        [
                            'else' => [
                                'expect_next_parallel_group' => 'missing_group',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testRejectsDecisionRuleWithStepAndParallelGroupTargets(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must define exactly one of "expect_next" or "expect_next_parallel_group"');

        ProcessTemplateArrayFactory::fromArray([
            'key' => 'invoice',
            'steps' => [
                ['key' => 'invoice_checked'],
                ['key' => 'booked'],
            ],
            'parallel_groups' => [
                [
                    'key' => 'booking_and_payment',
                    'required_steps' => ['booked'],
                ],
            ],
            'decision_points' => [
                [
                    'key' => 'booking_route',
                    'after' => 'invoice_checked',
                    'rules' => [
                        [
                            'else' => [
                                'expect_next' => 'booked',
                                'expect_next_parallel_group' => 'booking_and_payment',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testBuildsDecisionRuleWithInAndExistsOperators(): void
    {
        $template = ProcessTemplateArrayFactory::fromArray([
            'key' => 'invoice',
            'decision_points' => [
                [
                    'key' => 'route_by_type',
                    'required_fields' => ['documentType', 'signaturePresent'],
                    'rules' => [
                        [
                            'when' => [
                                'documentType' => [
                                    'in' => ['invoice', 'credit_note'],
                                ],
                            ],
                            'expect_next' => 'commercial_check',
                        ],
                        [
                            'when' => [
                                'signaturePresent' => [
                                    'exists' => true,
                                ],
                            ],
                            'expect_next' => 'signature_check',
                        ],
                    ],
                ],
            ],
        ]);

        $rules = $template->decisionPoints[0]->rules;
        self::assertSame('in', $rules[0]->condition?->operator);
        self::assertSame(['invoice', 'credit_note'], $rules[0]->condition?->value);
        self::assertSame('exists', $rules[1]->condition?->operator);
        self::assertTrue($rules[1]->condition?->value);
    }

    public function testInvalidDecisionRuleOperatorThrowsClearException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported decision rule operator "starts_with".');

        ProcessTemplateArrayFactory::fromArray([
            'key' => 'invoice',
            'decision_points' => [
                [
                    'key' => 'approval_route',
                    'rules' => [
                        [
                            'when' => [
                                'amount' => [
                                    'starts_with' => '10',
                                ],
                            ],
                            'expect_next' => 'manual_check',
                        ],
                    ],
                ],
            ],
        ]);
    }
}
