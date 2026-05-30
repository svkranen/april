<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\DecisionPointCandidateDetector;
use PHPUnit\Framework\TestCase;

class DecisionPointCandidateDetectorTest extends TestCase
{
    public function testDetectsSameAfterStepWithDifferentNextSteps(): void
    {
        $candidates = (new DecisionPointCandidateDetector())->detect([
            [
                'document_uuid' => 'doc-a',
                'steps' => ['invoice_checked', 'department_approval'],
            ],
            [
                'document_uuid' => 'doc-b',
                'steps' => ['invoice_checked', 'gf_approval'],
            ],
        ]);

        self::assertCount(1, $candidates);
        self::assertSame('invoice_checked', $candidates[0]->afterStepKey);
        self::assertSame(['department_approval', 'gf_approval'], $candidates[0]->observedNextSteps);
        self::assertSame(2, $candidates[0]->documentCount);
        self::assertSame(1.0, $candidates[0]->confidence);
        self::assertSame(['doc-a', 'doc-b'], $candidates[0]->documentUuids);
    }

    public function testIgnoresLinearTransitionsWithoutAlternativeNextStep(): void
    {
        $candidates = (new DecisionPointCandidateDetector())->detect([
            [
                'document_uuid' => 'doc-a',
                'steps' => ['received', 'checked', 'approved'],
            ],
            [
                'document_uuid' => 'doc-b',
                'steps' => ['received', 'checked', 'approved'],
            ],
        ]);

        self::assertSame([], $candidates);
    }

    public function testReportsConfidenceAgainstDocumentsWithTransitions(): void
    {
        $candidates = (new DecisionPointCandidateDetector())->detect([
            [
                'document_uuid' => 'doc-a',
                'steps' => ['invoice_checked', 'department_approval'],
            ],
            [
                'document_uuid' => 'doc-b',
                'steps' => ['invoice_checked', 'gf_approval'],
            ],
            [
                'document_uuid' => 'doc-c',
                'steps' => ['received', 'archived'],
            ],
        ]);

        self::assertCount(1, $candidates);
        self::assertSame(2, $candidates[0]->documentCount);
        self::assertSame(0.6667, $candidates[0]->confidence);
        self::assertSame(['doc-a', 'doc-b'], $candidates[0]->documentUuids);
    }

    public function testAcceptsExistingSuggestionStepShape(): void
    {
        $candidates = (new DecisionPointCandidateDetector())->detect([
            [
                'document_uuid' => 'doc-a',
                'steps' => [
                    ['key' => 'Invoice Checked', 'normalized' => 'invoice_checked'],
                    ['key' => 'Department Approval', 'normalized' => 'department_approval'],
                ],
            ],
            [
                'document_uuid' => 'doc-b',
                'steps' => [
                    ['key' => 'Invoice Checked', 'normalized' => 'invoice_checked'],
                    ['key' => 'GF Approval', 'normalized' => 'gf_approval'],
                ],
            ],
        ]);

        self::assertCount(1, $candidates);
        self::assertSame('Invoice Checked', $candidates[0]->afterStepKey);
        self::assertSame(['Department Approval', 'GF Approval'], $candidates[0]->observedNextSteps);
    }

    public function testDeduplicatesDirectRepeatedSteps(): void
    {
        $candidates = (new DecisionPointCandidateDetector())->detect([
            [
                'document_uuid' => 'doc-a',
                'steps' => ['invoice_checked', 'invoice_checked', 'department_approval'],
            ],
            [
                'document_uuid' => 'doc-b',
                'steps' => ['invoice_checked', 'gf_approval'],
            ],
        ]);

        self::assertCount(1, $candidates);
        self::assertSame(['department_approval', 'gf_approval'], $candidates[0]->observedNextSteps);
    }
}
