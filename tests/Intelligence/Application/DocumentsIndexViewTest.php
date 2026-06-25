<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\DocumentListFindingView;
use App\Intelligence\Application\DocumentListRow;
use App\Intelligence\Application\DocumentsIndexView;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class DocumentsIndexViewTest extends TestCase
{
    public function testWithoutFindingsShowsAllRowsAsNotCalculated(): void
    {
        $view = DocumentsIndexView::build([$this->row('a'), $this->row('b')], false, [], 'critical', 50);

        // Filtering is inactive without findings -> all rows shown.
        self::assertFalse($view->withFindings);
        self::assertCount(2, $view->entries);
        self::assertSame('not_calculated', $view->entries[0]['category']);
        self::assertNull($view->entries[0]['finding']);
    }

    public function testInvalidSeverityNormalisesToAll(): void
    {
        $view = DocumentsIndexView::build([$this->row('a')], true, ['a' => $this->finding('a', 'ok')], 'nonsense', 50);

        self::assertSame('all', $view->severity);
        self::assertCount(1, $view->entries);
    }

    public function testFilterCriticalShowsOnlyCritical(): void
    {
        $findings = [
            'a' => $this->finding('a', 'critical'),
            'b' => $this->finding('b', 'ok'),
            'c' => $this->finding('c', 'deviation'),
        ];
        $view = DocumentsIndexView::build([$this->row('a'), $this->row('b'), $this->row('c')], true, $findings, 'critical', 50);

        self::assertSame('critical', $view->severity);
        self::assertSame(3, $view->totalCount);
        self::assertSame(1, $view->shownCount);
        self::assertSame('a', $view->entries[0]['row']->documentUuid);
    }

    public function testFilterDeviationWarningTechnicalOk(): void
    {
        $findings = [
            'crit' => $this->finding('crit', 'critical'),
            'dev' => $this->finding('dev', 'deviation'),
            'warn' => $this->finding('warn', 'warning'),
            'tech' => $this->finding('tech', 'technical'),
            'ok' => $this->finding('ok', 'ok'),
        ];
        $rows = [$this->row('crit'), $this->row('dev'), $this->row('warn'), $this->row('tech'), $this->row('ok')];

        foreach (['deviation' => 'dev', 'warning' => 'warn', 'technical' => 'tech', 'ok' => 'ok'] as $severity => $uuid) {
            $view = DocumentsIndexView::build($rows, true, $findings, $severity, 50);
            self::assertSame(1, $view->shownCount, $severity);
            self::assertSame($uuid, $view->entries[0]['row']->documentUuid, $severity);
        }
    }

    public function testNotCalculatedFilterShowsRowsBeyondLimit(): void
    {
        // Two rows, only the first has a computed finding (limit = 1).
        $rows = [$this->row('a'), $this->row('b')];
        $findings = ['a' => $this->finding('a', 'ok')]; // 'b' beyond limit -> not in map

        $view = DocumentsIndexView::build($rows, true, $findings, 'not_calculated', 1);

        self::assertTrue($view->limitReached);
        self::assertSame(1, $view->shownCount);
        self::assertSame('b', $view->entries[0]['row']->documentUuid);
        self::assertSame('not_calculated', $view->entries[0]['category']);
    }

    public function testFailedFindingCountsAsTechnicalNotNotCalculated(): void
    {
        $findings = ['a' => DocumentListFindingView::failed('a', 'boom')];
        $view = DocumentsIndexView::build([$this->row('a')], true, $findings, 'technical', 50);

        self::assertSame(1, $view->shownCount);
        self::assertSame('technical', $view->entries[0]['category']);

        $notCalculated = DocumentsIndexView::build([$this->row('a')], true, $findings, 'not_calculated', 50);
        self::assertSame(0, $notCalculated->shownCount);
    }

    public function testAllShowsEverythingIncludingBeyondLimit(): void
    {
        $rows = [$this->row('a'), $this->row('b')];
        $findings = ['a' => $this->finding('a', 'critical')];

        $view = DocumentsIndexView::build($rows, true, $findings, 'all', 1);

        self::assertSame(2, $view->shownCount);
    }

    public function testStepFilterKeepsOnlyDocumentsWithThatStep(): void
    {
        $findings = [
            'a' => $this->finding('a', 'critical', ['01']),
            'b' => $this->finding('b', 'warning', ['02']),
            'c' => $this->finding('c', 'critical', []), // process-wide finding, no step
        ];
        $rows = [$this->row('a'), $this->row('b'), $this->row('c')];

        $view = DocumentsIndexView::build($rows, true, $findings, 'all', 50, '01', '01 Eingang');

        self::assertSame('01', $view->stepKey);
        self::assertSame('01 Eingang', $view->stepLabel);
        self::assertSame(1, $view->shownCount);
        self::assertSame('a', $view->entries[0]['row']->documentUuid);
    }

    public function testStepFilterCombinesWithSeverityFilter(): void
    {
        $findings = [
            'a' => $this->finding('a', 'critical', ['01']),
            'b' => $this->finding('b', 'warning', ['01']),
        ];
        $rows = [$this->row('a'), $this->row('b')];

        $view = DocumentsIndexView::build($rows, true, $findings, 'warning', 50, '01', '01 Eingang');

        self::assertSame(1, $view->shownCount);
        self::assertSame('b', $view->entries[0]['row']->documentUuid);
    }

    public function testStepFilterIsIgnoredWithoutFindings(): void
    {
        $view = DocumentsIndexView::build([$this->row('a'), $this->row('b')], false, [], 'all', 50, '01', '01 Eingang');

        // Without findings the step filter cannot act and is not advertised.
        self::assertNull($view->stepKey);
        self::assertCount(2, $view->entries);
    }

    private function row(string $uuid): DocumentListRow
    {
        return new DocumentListRow($uuid, 'DOC', 1, 1, new DateTimeImmutable('2026-06-15T09:00:00+00:00'));
    }

    /**
     * @param array<int, string> $stepKeys
     */
    private function finding(string $uuid, string $severity, array $stepKeys = []): DocumentListFindingView
    {
        return new DocumentListFindingView($uuid, $severity, ucfirst($severity), 'vs-ok', 1, ['process' => 0, 'context' => 0, 'access' => 0, 'technical' => 0], null, $stepKeys);
    }
}
