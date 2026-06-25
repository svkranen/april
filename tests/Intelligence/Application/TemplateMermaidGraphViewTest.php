<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\FindingSeverityFilter;
use App\Intelligence\Application\StepFindingSummary;
use App\Intelligence\Application\TemplateGraphFindings;
use App\Intelligence\Application\TemplateMermaidGraphView;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateStep;
use PHPUnit\Framework\TestCase;

class TemplateMermaidGraphViewTest extends TestCase
{
    public function testWithoutFindingsAllStepsAreNotCalculated(): void
    {
        $view = TemplateMermaidGraphView::build($this->template(), false, null, "flowchart TD\n", 50);

        self::assertFalse($view->withFindings);
        self::assertSame("flowchart TD\n", $view->mermaidCode);
        self::assertCount(2, $view->steps);
        self::assertSame(FindingSeverityFilter::NOT_CALCULATED, $view->steps[0]['status']);
        self::assertSame('—', $view->steps[0]['findingsLabel']);
        // Legend covers all six statuses.
        self::assertCount(6, $view->legend);
        self::assertFalse($view->hasProcessFindings());
    }

    public function testWithFindingsExposesStatusLabelsAndCounters(): void
    {
        $findings = new TemplateGraphFindings(
            [
                '01' => StepFindingSummary::fromSeverities('01', [FindingSeverityFilter::WARNING], true),
                '02' => StepFindingSummary::fromSeverities('02', [], true),
            ],
            12,
            10,
            true,
            2,
            1,
            0
        );

        $view = TemplateMermaidGraphView::build($this->template(), true, $findings, "flowchart TD\n", 10);

        self::assertTrue($view->withFindings);
        self::assertSame(FindingSeverityFilter::WARNING, $view->steps[0]['status']);
        self::assertSame('1 Warnung', $view->steps[0]['findingsLabel']);
        self::assertSame('OK', $view->steps[1]['findingsLabel']);
        self::assertSame(12, $view->totalDocuments);
        self::assertSame(10, $view->processedDocuments);
        self::assertTrue($view->limitReached);
        self::assertTrue($view->hasProcessFindings());
        self::assertSame(2, $view->processDeviations);
        self::assertSame(1, $view->processWarnings);
    }

    private function template(): ProcessTemplate
    {
        return new ProcessTemplate(
            key: 'ai-rechnungen',
            version: '1',
            steps: [new ProcessTemplateStep('01', 'Erster Schritt'), new ProcessTemplateStep('02', 'Zweiter Schritt')],
        );
    }
}
