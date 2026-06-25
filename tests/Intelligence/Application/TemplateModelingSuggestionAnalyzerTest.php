<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\AttributedFinding;
use App\Intelligence\Application\FindingSeverityFilter;
use App\Intelligence\Application\TemplateGraphFindings;
use App\Intelligence\Application\TemplateModelingSuggestion;
use App\Intelligence\Application\TemplateModelingSuggestionAnalyzer;
use PHPUnit\Framework\TestCase;

class TemplateModelingSuggestionAnalyzerTest extends TestCase
{
    private function analyzer(): TemplateModelingSuggestionAnalyzer
    {
        return new TemplateModelingSuggestionAnalyzer();
    }

    public function testNullFindingsMeansNotComputedWithoutSuggestions(): void
    {
        $view = $this->analyzer()->fromFindings(null);

        // Without findings nothing expensive runs and no suggestion is invented.
        self::assertFalse($view->withFindings);
        self::assertFalse($view->hasSuggestions());
        self::assertSame([], $view->suggestions);
    }

    public function testDecisionFindingProducesModellingSuggestion(): void
    {
        $findings = $this->findings([
            new AttributedFinding(
                AttributedFinding::TARGET_GATEWAY,
                'approval',
                FindingSeverityFilter::DEVIATION,
                'Abweichung',
                'Decision rule violation: approval ...',
                3,
                2,
                'approval'
            ),
        ]);

        $view = $this->analyzer()->fromFindings($findings);

        self::assertTrue($view->withFindings);
        self::assertCount(1, $view->suggestions);
        $suggestion = $view->suggestions[0];
        self::assertSame('decision_rule_violation', $suggestion->type);
        self::assertSame(TemplateModelingSuggestion::STATUS_REVIEW, $suggestion->status);
        self::assertStringContainsString('approval', $suggestion->description);
        self::assertSame(2, $suggestion->documentCount);
    }

    public function testTransitionFindingProducesModellingSuggestion(): void
    {
        $findings = $this->findings([
            new AttributedFinding(
                AttributedFinding::TARGET_TRANSITION,
                '02 → 01',
                FindingSeverityFilter::DEVIATION,
                'Abweichung',
                'Transition violation: 02 ...',
                5,
                4,
                null,
                '02',
                '01'
            ),
        ]);

        $suggestion = $this->analyzer()->fromFindings($findings)->suggestions[0];

        self::assertSame('observed_transition_deviation', $suggestion->type);
        self::assertSame(TemplateModelingSuggestion::STATUS_REVIEW, $suggestion->status);
        self::assertStringContainsString('02 → 01', $suggestion->description);
        self::assertSame(4, $suggestion->documentCount);
    }

    public function testProcessWideFindingsProduceSuggestion(): void
    {
        // No attributed findings, but process-wide deviations exist.
        $findings = $this->findings([], processDeviations: 2, processWarnings: 1);

        $view = $this->analyzer()->fromFindings($findings);

        self::assertCount(1, $view->suggestions);
        $suggestion = $view->suggestions[0];
        self::assertSame('process_wide_findings', $suggestion->type);
        // Process-wide deviations need a real modelling decision -> review.
        self::assertSame(TemplateModelingSuggestion::STATUS_REVIEW, $suggestion->status);
        self::assertNull($suggestion->documentCount);
    }

    public function testProcessWideWarningsOnlyAreOptional(): void
    {
        $findings = $this->findings([], processDeviations: 0, processWarnings: 0, processTechnical: 1);

        $suggestion = $this->analyzer()->fromFindings($findings)->suggestions[0];

        self::assertSame('process_wide_findings', $suggestion->type);
        self::assertSame(TemplateModelingSuggestion::STATUS_OPTIONAL, $suggestion->status);
    }

    public function testComputedButCleanFindingsYieldNoSuggestions(): void
    {
        $view = $this->analyzer()->fromFindings($this->findings([]));

        // withFindings is true (it was computed) but nothing to decide.
        self::assertTrue($view->withFindings);
        self::assertFalse($view->hasSuggestions());
    }

    /**
     * @param array<int, AttributedFinding> $attributed
     */
    private function findings(
        array $attributed,
        int $processDeviations = 0,
        int $processWarnings = 0,
        int $processTechnical = 0
    ): TemplateGraphFindings {
        return new TemplateGraphFindings(
            [],
            10,
            10,
            false,
            $processDeviations,
            $processWarnings,
            $processTechnical,
            [],
            $attributed
        );
    }
}
