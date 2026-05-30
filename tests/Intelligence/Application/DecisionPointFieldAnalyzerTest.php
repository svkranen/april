<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\DecisionPointCandidate;
use App\Intelligence\Application\DecisionPointFieldAnalyzer;
use PHPUnit\Framework\TestCase;

class DecisionPointFieldAnalyzerTest extends TestCase
{
    public function testAmountDiffersBetweenNextSteps(): void
    {
        $evidence = (new DecisionPointFieldAnalyzer())->analyze(
            $this->candidate(),
            [
                $this->transition('doc-a', 'department_approval', ['amount' => 5000]),
                $this->transition('doc-b', 'gf_approval', ['amount' => 25000]),
            ]
        );

        self::assertCount(1, $evidence);
        self::assertSame('amount', $evidence[0]->fieldKey);
        self::assertSame([
            'department_approval' => [5000],
            'gf_approval' => [25000],
        ], $evidence[0]->observedValuesByNextStep);
        self::assertSame(1.0, $evidence[0]->coverage);
        self::assertSame(2, $evidence[0]->distinctValueCount);
    }

    public function testConstantFieldIsIgnored(): void
    {
        $evidence = (new DecisionPointFieldAnalyzer())->analyze(
            $this->candidate(),
            [
                $this->transition('doc-a', 'department_approval', ['currency' => 'EUR']),
                $this->transition('doc-b', 'gf_approval', ['currency' => 'EUR']),
            ]
        );

        self::assertSame([], $evidence);
    }

    public function testMissingValuesReduceCoverage(): void
    {
        $evidence = (new DecisionPointFieldAnalyzer())->analyze(
            $this->candidate(),
            [
                $this->transition('doc-a', 'department_approval', ['amount' => 5000]),
                $this->transition('doc-b', 'gf_approval', ['amount' => 25000]),
                $this->transition('doc-c', 'gf_approval', []),
            ]
        );

        self::assertCount(1, $evidence);
        self::assertSame('amount', $evidence[0]->fieldKey);
        self::assertSame(0.6667, $evidence[0]->coverage);
        self::assertSame([
            'department_approval' => [5000],
            'gf_approval' => [25000],
        ], $evidence[0]->observedValuesByNextStep);
    }

    public function testMultipleNextStepsAreReportedSeparately(): void
    {
        $candidate = new DecisionPointCandidate(
            'invoice_checked',
            ['department_approval', 'gf_approval', 'board_approval'],
            3,
            1.0,
            ['doc-a', 'doc-b', 'doc-c']
        );

        $evidence = (new DecisionPointFieldAnalyzer())->analyze(
            $candidate,
            [
                $this->transition('doc-a', 'department_approval', ['amount' => 5000]),
                $this->transition('doc-b', 'gf_approval', ['amount' => 25000]),
                $this->transition('doc-c', 'board_approval', ['amount' => 100000]),
            ]
        );

        self::assertCount(1, $evidence);
        self::assertSame([
            'department_approval' => [5000],
            'gf_approval' => [25000],
            'board_approval' => [100000],
        ], $evidence[0]->observedValuesByNextStep);
        self::assertSame(3, $evidence[0]->distinctValueCount);
    }

    private function candidate(): DecisionPointCandidate
    {
        return new DecisionPointCandidate(
            'invoice_checked',
            ['department_approval', 'gf_approval'],
            2,
            1.0,
            ['doc-a', 'doc-b', 'doc-c']
        );
    }

    /**
     * @param array<string, mixed> $context
     * @return array{document_uuid: string, after_step_key: string, next_step_key: string, context: array<string, mixed>}
     */
    private function transition(string $documentUuid, string $nextStepKey, array $context): array
    {
        return [
            'document_uuid' => $documentUuid,
            'after_step_key' => 'invoice_checked',
            'next_step_key' => $nextStepKey,
            'context' => $context,
        ];
    }
}
