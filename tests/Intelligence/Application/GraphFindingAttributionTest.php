<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\FindingAttribution;
use App\Intelligence\Application\GraphFindingAttribution;
use App\Intelligence\Application\ProcessTemplateGraphFactory;
use App\Intelligence\Domain\ProcessDeviation;
use PHPUnit\Framework\TestCase;

class GraphFindingAttributionTest extends TestCase
{
    private function attribution(): GraphFindingAttribution
    {
        return new GraphFindingAttribution();
    }

    public function testDecisionViolationIsAttributedToExistingGateway(): void
    {
        $nodeId = ProcessTemplateGraphFactory::gatewayNodeId('approval');
        $deviation = ProcessDeviation::decisionRuleViolation('Decision rule violation: approval after a expected b but got c', 'approval', 'a', 'c');

        $result = $this->attribution()->attribute($deviation, [$nodeId => true]);

        self::assertSame(FindingAttribution::TARGET_GATEWAY, $result->target);
        self::assertSame($nodeId, $result->nodeId);
    }

    public function testDecisionViolationStaysProcessWhenGatewayIsNotInTheGraph(): void
    {
        $deviation = ProcessDeviation::decisionRuleViolation('Decision rule violation: ghost after a expected b but got c', 'ghost', 'a', 'c');

        // Gateway for "ghost" is not drawn -> no unambiguous anchor -> process-wide.
        self::assertTrue($this->attribution()->attribute($deviation, ['decision:approval' => true])->isProcess());
    }

    public function testDecisionViolationWithoutDecisionKeyStaysProcess(): void
    {
        $deviation = new ProcessDeviation(ProcessDeviation::TYPE_DECISION_RULE_VIOLATION, 'free text only');

        self::assertTrue($this->attribution()->attribute($deviation, ['decision:approval' => true])->isProcess());
    }

    public function testTransitionViolationWithStructureIsAttributedToEdge(): void
    {
        $deviation = ProcessDeviation::transitionViolation('Transition violation: A expected one of B but got C', 'A', 'C', ['B']);

        $result = $this->attribution()->attribute($deviation, []);

        self::assertSame(FindingAttribution::TARGET_TRANSITION, $result->target);
        self::assertSame('A', $result->from);
        self::assertSame('C', $result->actual);
    }

    public function testTransitionViolationWithoutActualStaysProcess(): void
    {
        // No free-text fallback: a missing structured endpoint must not be guessed.
        $deviation = new ProcessDeviation(ProcessDeviation::TYPE_TRANSITION_VIOLATION, 'Transition violation: A ...', from: 'A', actual: null);

        self::assertTrue($this->attribution()->attribute($deviation, [])->isProcess());
    }

    public function testOtherDeviationTypeStaysProcess(): void
    {
        $deviation = new ProcessDeviation(ProcessDeviation::TYPE_OTHER, 'Missing step: 02');

        self::assertTrue($this->attribution()->attribute($deviation, [])->isProcess());
    }
}
