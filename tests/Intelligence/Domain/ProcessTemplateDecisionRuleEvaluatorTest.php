<?php

namespace App\Tests\Intelligence\Domain;

use App\Intelligence\Domain\ProcessTemplateDecisionPoint;
use App\Intelligence\Domain\ProcessTemplateDecisionRule;
use App\Intelligence\Domain\ProcessTemplateDecisionRuleEvaluator;
use App\Intelligence\Domain\ProcessTemplateRuleCondition;
use PHPUnit\Framework\TestCase;

class ProcessTemplateDecisionRuleEvaluatorTest extends TestCase
{
    public function testGtMatchesExpectedRule(): void
    {
        $decisionPoint = $this->decisionPoint([
            $this->rule('amount', 'gt', 10000, 'gf_approval'),
            $this->elseRule('department_approval'),
        ]);

        self::assertSame('gf_approval', $this->evaluate($decisionPoint, ['amount' => 12000]));
    }

    public function testElseRuleIsFallback(): void
    {
        $decisionPoint = $this->decisionPoint([
            $this->rule('amount', 'gt', 10000, 'gf_approval'),
            $this->elseRule('department_approval'),
        ]);

        self::assertSame('department_approval', $this->evaluate($decisionPoint, ['amount' => 5000]));
    }

    public function testFirstMatchingRuleWins(): void
    {
        $decisionPoint = $this->decisionPoint([
            $this->rule('amount', 'gt', 10000, 'gf_approval'),
            $this->rule('amount', 'gt', 5000, 'department_approval'),
            $this->elseRule('manual_check'),
        ]);

        self::assertSame('gf_approval', $this->evaluate($decisionPoint, ['amount' => 12000]));
    }

    public function testMissingFieldDoesNotMatchNonExistsOperators(): void
    {
        $decisionPoint = $this->decisionPoint([
            $this->rule('amount', 'gt', 10000, 'gf_approval'),
        ]);

        self::assertNull($this->evaluate($decisionPoint, []));
    }

    public function testExistsTrueAndFalse(): void
    {
        self::assertSame(
            'has_amount',
            $this->evaluate($this->decisionPoint([
                $this->rule('amount', 'exists', true, 'has_amount'),
                $this->elseRule('missing_amount'),
            ]), ['amount' => 1])
        );

        self::assertSame(
            'missing_amount',
            $this->evaluate($this->decisionPoint([
                $this->rule('amount', 'exists', false, 'missing_amount'),
                $this->elseRule('has_amount'),
            ]), ['amount' => null])
        );
    }

    public function testInOperatorMatchesArrayList(): void
    {
        $decisionPoint = $this->decisionPoint([
            $this->rule('documentType', 'in', ['invoice', 'credit_note'], 'commercial_check'),
            $this->elseRule('manual_check'),
        ]);

        self::assertSame('commercial_check', $this->evaluate($decisionPoint, ['documentType' => 'invoice']));
        self::assertSame('manual_check', $this->evaluate($decisionPoint, ['documentType' => 'delivery_note']));
    }

    public function testInvalidOrNonEvaluableValuesDoNotThrowFatalError(): void
    {
        $decisionPoint = $this->decisionPoint([
            $this->rule('amount', 'gt', 10000, 'gf_approval'),
            $this->rule('documentType', 'in', 'invoice', 'commercial_check'),
            $this->rule('amount', 'unknown', 10000, 'unknown_route'),
            $this->elseRule('manual_check'),
        ]);

        self::assertSame('manual_check', $this->evaluate($decisionPoint, ['amount' => 'not-a-number', 'documentType' => 'invoice']));
    }

    public function testEqAndNeqUseComparableScalarValues(): void
    {
        self::assertSame(
            'invoice_route',
            $this->evaluate($this->decisionPoint([
                $this->rule('documentType', 'eq', 'invoice', 'invoice_route'),
            ]), ['documentType' => 'invoice'])
        );

        self::assertSame(
            'not_invoice_route',
            $this->evaluate($this->decisionPoint([
                $this->rule('documentType', 'neq', 'invoice', 'not_invoice_route'),
            ]), ['documentType' => 'credit_note'])
        );
    }

    private function evaluate(ProcessTemplateDecisionPoint $decisionPoint, array $context): ?string
    {
        return (new ProcessTemplateDecisionRuleEvaluator())->expectedNextStepKey($decisionPoint, $context);
    }

    /**
     * @param array<int, ProcessTemplateDecisionRule> $rules
     */
    private function decisionPoint(array $rules): ProcessTemplateDecisionPoint
    {
        return new ProcessTemplateDecisionPoint('approval_route', 'invoice_checked', [], $rules);
    }

    private function rule(string $field, string $operator, mixed $value, string $expectedNextStepKey): ProcessTemplateDecisionRule
    {
        return new ProcessTemplateDecisionRule(
            new ProcessTemplateRuleCondition($field, $operator, $value),
            $expectedNextStepKey
        );
    }

    private function elseRule(string $expectedNextStepKey): ProcessTemplateDecisionRule
    {
        return new ProcessTemplateDecisionRule(null, $expectedNextStepKey, true);
    }
}
