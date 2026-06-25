<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\DocumentCheckResultView;
use App\Intelligence\Application\DocumentFindingsView;
use App\Intelligence\Application\ProcessTemplateCheckResult;
use App\Intelligence\Application\VisibilityCheckResultRecord;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class DocumentFindingsViewTest extends TestCase
{
    public function testNoFindingsWhenCheckOkAndNoViolations(): void
    {
        $view = DocumentFindingsView::fromData($this->okCheck(), [
            $this->record('ok'), // ok -> no finding
        ]);

        self::assertSame('ok', $view->overallSeverity);
        self::assertSame('OK', $view->overallLabel);
        self::assertFalse($view->hasFindings);
        self::assertSame(0, $view->total);
    }

    public function testProcessDeviationLeadsToDeviationOverall(): void
    {
        $check = DocumentCheckResultView::fromResult(new ProcessTemplateCheckResult(
            ['01', '02'], ['01'], ['Pflichtschritt 02 fehlt'], [], [], null, []
        ));

        $view = DocumentFindingsView::fromData($check, []);

        self::assertSame('deviation', $view->overallSeverity);
        self::assertSame('Abweichung', $view->overallLabel);
        self::assertSame(1, $view->countsByCategory['process']);
        self::assertSame('process', $view->findings[0]['category']);
    }

    public function testAccessViolationLeadsToCriticalOverall(): void
    {
        $view = DocumentFindingsView::fromData($this->okCheck(), [
            $this->record('violation'),
            $this->record('ok'),
        ]);

        self::assertSame('critical', $view->overallSeverity);
        self::assertSame('Kritisch', $view->overallLabel);
        self::assertSame(1, $view->countsByCategory['access']);
        // critical is sorted first.
        self::assertSame('access', $view->findings[0]['category']);
        self::assertSame('critical', $view->findings[0]['severity']);
        self::assertSame('external_today', $view->findings[0]['probeKey']);
    }

    public function testContextWarningLeadsToWarningOverall(): void
    {
        $check = DocumentCheckResultView::fromResult(new ProcessTemplateCheckResult(
            [], [], [], [], ['Context fehlt: standort'], null, []
        ));

        $view = DocumentFindingsView::fromData($check, [$this->record('warning')]);

        self::assertSame('warning', $view->overallSeverity);
        self::assertSame(1, $view->countsByCategory['context']);
        self::assertSame(1, $view->countsByCategory['access']);
    }

    public function testUnknownAccessLeadsToTechnicalOverall(): void
    {
        $view = DocumentFindingsView::fromData($this->okCheck(), [$this->record('unknown')]);

        self::assertSame('technical', $view->overallSeverity);
        self::assertSame(1, $view->countsByCategory['technical']);
    }

    public function testUnavailableCheckProducesTechnicalFinding(): void
    {
        $view = DocumentFindingsView::fromData(DocumentCheckResultView::unavailable('boom'), []);

        self::assertSame('technical', $view->overallSeverity);
        self::assertSame(1, $view->countsByCategory['technical']);
        self::assertSame('boom', $view->findings[0]['reason']);
    }

    public function testCriticalOutranksDeviationAndWarning(): void
    {
        $check = DocumentCheckResultView::fromResult(new ProcessTemplateCheckResult(
            [], [], ['some deviation'], [], ['some warning'], null, []
        ));

        $view = DocumentFindingsView::fromData($check, [$this->record('violation')]);

        self::assertSame('critical', $view->overallSeverity);
        self::assertSame(3, $view->total);
        // Sorted: critical first.
        self::assertSame('critical', $view->findings[0]['severity']);
    }

    private function okCheck(): DocumentCheckResultView
    {
        return DocumentCheckResultView::fromResult(new ProcessTemplateCheckResult([], [], []));
    }

    private function record(string $status): VisibilityCheckResultRecord
    {
        return new VisibilityCheckResultRecord(
            1, 'doc-1', 1, 'ai-rechnungen', 'amagno', '01 Rechnungseingang', 'after', 'route', 'profile',
            'external_today', 'amagno_magnet_documents', '1009', 'hidden', 'visible', $status, 'forbidden_visibility',
            new DateTimeImmutable('2026-06-15T10:00:00+00:00'), 1, true, 1, null
        );
    }
}
