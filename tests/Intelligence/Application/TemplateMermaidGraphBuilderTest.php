<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\FindingSeverityFilter;
use App\Intelligence\Application\ProcessTemplateGraphFactory;
use App\Intelligence\Application\StepFindingSummary;
use App\Intelligence\Application\TemplateGraphFindings;
use App\Intelligence\Application\TemplateMermaidGraphBuilder;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateDecisionPoint;
use App\Intelligence\Domain\ProcessTemplateDecisionRule;
use App\Intelligence\Domain\ProcessTemplateStep;
use App\Intelligence\Domain\ProcessTemplateTransition;
use PHPUnit\Framework\TestCase;

class TemplateMermaidGraphBuilderTest extends TestCase
{
    private function builder(): TemplateMermaidGraphBuilder
    {
        return new TemplateMermaidGraphBuilder(new ProcessTemplateGraphFactory());
    }

    public function testRendersStepsEdgesAndNotCalculatedClassesWithoutFindings(): void
    {
        $template = new ProcessTemplate(
            key: 'invoice',
            steps: [new ProcessTemplateStep('A', 'Erster'), new ProcessTemplateStep('B', 'Zweiter')],
            transitions: [new ProcessTemplateTransition('A', 'B')],
        );

        $code = $this->builder()->build($template, null);

        self::assertStringStartsWith("flowchart TD\n", $code);
        self::assertStringContainsString('n_A["Erster"]', $code);
        self::assertStringContainsString('n_B["Zweiter"]', $code);
        self::assertStringContainsString('n_A --> n_B', $code);
        // Start/End are added by the shared factory and connect the flow.
        self::assertStringContainsString('n_start --> n_A', $code);
        // Step nodes are not_calculated without findings; structure nodes are neutral.
        self::assertStringContainsString('class n_A not_calculated', $code);
        self::assertStringContainsString('class n_start structure', $code);
        foreach (['critical', 'deviation', 'warning', 'technical', 'ok', 'not_calculated', 'structure'] as $status) {
            self::assertStringContainsString('classDef '.$status.' ', $code);
        }
    }

    public function testRendersStatusClassesAndSecondLabelLineWithFindings(): void
    {
        $template = new ProcessTemplate(
            key: 'invoice',
            steps: [new ProcessTemplateStep('A', 'Erster'), new ProcessTemplateStep('B', 'Zweiter')],
            transitions: [new ProcessTemplateTransition('A', 'B')],
        );
        $findings = new TemplateGraphFindings(
            [
                'A' => StepFindingSummary::fromSeverities('A', [FindingSeverityFilter::CRITICAL, FindingSeverityFilter::WARNING], true),
                'B' => StepFindingSummary::fromSeverities('B', [], true),
            ],
            3, 3, false, 0, 0, 0
        );

        $code = $this->builder()->build($template, $findings);

        self::assertStringContainsString('n_A["Erster<br/>1 Kritisch / 1 Warnung"]', $code);
        self::assertStringContainsString('class n_A critical', $code);
        self::assertStringContainsString('n_B["Zweiter<br/>OK"]', $code);
        self::assertStringContainsString('class n_B ok', $code);
    }

    public function testRendersDecisionGatewayAndEdges(): void
    {
        $template = new ProcessTemplate(
            key: 'invoice',
            steps: [new ProcessTemplateStep('A', 'Erster'), new ProcessTemplateStep('B', 'Zweiter')],
            decisionPoints: [new ProcessTemplateDecisionPoint('decide', 'A', [], [new ProcessTemplateDecisionRule(null, 'B')])],
        );

        $code = $this->builder()->build($template, null);

        // Decision becomes an exclusive-gateway node, wired in after its step.
        self::assertStringContainsString('n_decision_decide{"decide"}', $code);
        self::assertStringContainsString('n_A --> n_decision_decide', $code);
        self::assertStringContainsString('| n_B', $code);
        self::assertStringContainsString('class n_decision_decide structure', $code);
    }

    public function testColoursDecisionGatewayWhenADecisionFindingIsAttributedToIt(): void
    {
        $template = new ProcessTemplate(
            key: 'invoice',
            steps: [new ProcessTemplateStep('A', 'Erster'), new ProcessTemplateStep('B', 'Zweiter')],
            decisionPoints: [new ProcessTemplateDecisionPoint('decide', 'A', [], [new ProcessTemplateDecisionRule(null, 'B')])],
        );
        $findings = new TemplateGraphFindings(
            ['A' => StepFindingSummary::fromSeverities('A', [], true), 'B' => StepFindingSummary::fromSeverities('B', [], true)],
            1, 1, false, 0, 0, 0,
            ['decision:decide' => FindingSeverityFilter::DEVIATION]
        );

        $code = $this->builder()->build($template, $findings);

        // Gateway is coloured by its attributed finding; the step nodes are unaffected.
        self::assertStringContainsString('class n_decision_decide deviation', $code);
        self::assertStringContainsString('class n_A ok', $code);
    }

    public function testFallsBackToImplicitStepOrderWhenNoTransitions(): void
    {
        $template = new ProcessTemplate(
            key: 'invoice',
            steps: [
                new ProcessTemplateStep('A', 'Erster'),
                new ProcessTemplateStep('B', 'Zweiter'),
                new ProcessTemplateStep('C', 'Dritter'),
            ],
        );

        $code = $this->builder()->build($template, null);

        // The factory emits dashed "default order" edges when nothing is declared.
        self::assertStringContainsString('n_A -.->|"default order"| n_B', $code);
        self::assertStringContainsString('n_B -.->|"default order"| n_C', $code);
    }

    public function testEscapesDoubleQuotesInStepLabels(): void
    {
        $template = new ProcessTemplate(
            key: 'invoice',
            steps: [new ProcessTemplateStep('A', 'Schritt "Sonderfall"')],
        );

        $code = $this->builder()->build($template, null);

        self::assertStringContainsString('Schritt &quot;Sonderfall&quot;', $code);
        self::assertStringNotContainsString('Schritt "Sonderfall"]', $code);
    }
}
