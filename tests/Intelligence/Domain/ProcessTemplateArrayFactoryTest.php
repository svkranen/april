<?php

namespace App\Tests\Intelligence\Domain;

use App\Intelligence\Domain\ProcessTemplateArrayFactory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ProcessTemplateArrayFactoryTest extends TestCase
{
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
            'transitions' => [
                ['from' => 'received', 'to' => 'approved'],
                ['from' => 'approved', 'to' => 'booked'],
            ],
        ]);

        self::assertCount(2, $template->transitions);
        self::assertSame('received', $template->transitions[0]->from);
        self::assertSame('approved', $template->transitions[0]->to);
        self::assertSame('approved', $template->transitions[1]->from);
        self::assertSame('booked', $template->transitions[1]->to);
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
                ],
            ],
        ]);

        self::assertCount(1, $template->parallelGroups);
        self::assertSame('approval_group', $template->parallelGroups[0]->key);
        self::assertSame('received', $template->parallelGroups[0]->after);
        self::assertSame(['manager_approval', 'finance_approval'], $template->parallelGroups[0]->requiredStepKeys);
        self::assertSame('any', $template->parallelGroups[0]->order);
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

        self::assertSame('tag-project-id', $template->fieldMappings['project_id']->tagId);
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
