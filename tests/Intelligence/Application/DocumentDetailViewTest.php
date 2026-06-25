<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\DocumentDetailView;
use App\Intelligence\Application\DocumentTimelineEventRow;
use App\Intelligence\Application\DocumentTimelineReport;
use App\Intelligence\Application\VisibilityCheckResultRecord;
use App\Intelligence\Domain\ProcessTemplate;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class DocumentDetailViewTest extends TestCase
{
    public function testGroupsEventsByStepWithBeforeAfterPhases(): void
    {
        $template = new ProcessTemplate(key: 'ai-rechnungen', version: '1.1');

        $report = new DocumentTimelineReport('doc-1', [], [
            $this->event('01 Rechnungseingang', 'before', 'eingang.before', '10:00'),
            $this->event('01 Rechnungseingang', 'after', 'eingang.after', '10:01'),
            $this->event('02 Freigabe', 'after', 'freigabe.after', '11:00'),
            $this->event('02 Freigabe', 'unknown', 'freigabe.unknown', '11:05'),
            // Different process -> must be filtered out.
            $this->event('99 Other', 'after', 'other', '12:00', processKey: 'other-process'),
        ]);

        $view = DocumentDetailView::fromData($template, 'doc-1', $report, []);

        self::assertTrue($view->hasTimeline);
        self::assertSame(4, $view->eventCount); // other-process event excluded

        // Two business steps only - before/after do NOT create extra steps.
        self::assertCount(2, $view->steps);
        self::assertSame('01 Rechnungseingang', $view->steps[0]['stepKey']);
        self::assertCount(1, $view->steps[0]['phases']['before']);
        self::assertCount(1, $view->steps[0]['phases']['after']);
        self::assertSame([], $view->steps[0]['phases']['unknown']);
        self::assertSame(2, $view->steps[0]['eventCount']);

        self::assertSame('02 Freigabe', $view->steps[1]['stepKey']);
        self::assertCount(1, $view->steps[1]['phases']['after']);
        // unknown phase stays inside the step group (rendered expert-only).
        self::assertCount(1, $view->steps[1]['phases']['unknown']);

        // No step key appears twice.
        $stepKeys = array_column($view->steps, 'stepKey');
        self::assertSame($stepKeys, array_values(array_unique($stepKeys)));
    }

    public function testEmptyTimelineWhenNoEventsForProcess(): void
    {
        $template = new ProcessTemplate(key: 'ai-rechnungen', version: '1.1');
        $report = new DocumentTimelineReport('doc-1', [], [
            $this->event('x', 'after', 'e', '10:00', processKey: 'other'),
        ]);

        $view = DocumentDetailView::fromData($template, 'doc-1', $report, []);

        self::assertFalse($view->hasTimeline);
        self::assertSame(0, $view->eventCount);
        self::assertSame([], $view->steps);
    }

    public function testGroupsVisibilityResultsByStepPhaseCheck(): void
    {
        $template = new ProcessTemplate(key: 'ai-rechnungen', version: '1.1');
        $report = new DocumentTimelineReport('doc-1', [], []);

        $records = [
            $this->record('01 Rechnungseingang', 'after', 'route', 'approval_location_a_today', 'ok'),
            $this->record('01 Rechnungseingang', 'after', 'route', 'external_today', 'violation'),
            $this->record('02 Freigabe', 'after', 'check2', 'probe_x', 'warning'),
        ];

        $view = DocumentDetailView::fromData($template, 'doc-1', $report, $records);

        self::assertTrue($view->hasVisibilityResults);
        self::assertSame(3, $view->visibilityResultCount);
        // Two groups: (step,after,route) holds 2 records, (step2,after,check2) holds 1.
        self::assertCount(2, $view->visibilityGroups);
        self::assertSame('route', $view->visibilityGroups[0]['checkKey']);
        self::assertCount(2, $view->visibilityGroups[0]['records']);
        self::assertSame('check2', $view->visibilityGroups[1]['checkKey']);
        self::assertCount(1, $view->visibilityGroups[1]['records']);
    }

    public function testNoVisibilityResults(): void
    {
        $template = new ProcessTemplate(key: 'ai-rechnungen', version: '1.1');
        $report = new DocumentTimelineReport('doc-1', [], []);

        $view = DocumentDetailView::fromData($template, 'doc-1', $report, []);

        self::assertFalse($view->hasVisibilityResults);
        self::assertSame([], $view->visibilityGroups);
    }

    private function event(
        string $stepKey,
        string $phase,
        string $eventKey,
        string $time,
        string $processKey = 'ai-rechnungen'
    ): DocumentTimelineEventRow {
        return new DocumentTimelineEventRow(
            externalEventKey: 'ext-'.$eventKey,
            eventKey: $eventKey,
            stepKey: $stepKey,
            processKey: $processKey,
            documentVersion: 1,
            occurredAt: new DateTimeImmutable('2026-06-15T'.$time.':00+00:00'),
            receivedAt: new DateTimeImmutable('2026-06-15T'.$time.':05+00:00'),
            id: 1,
            processInstanceId: 1,
            contextSummary: null,
            eventPhase: $phase
        );
    }

    private function record(string $stepKey, string $phase, string $checkKey, string $probeKey, string $status): VisibilityCheckResultRecord
    {
        return new VisibilityCheckResultRecord(
            1, 'doc-1', 1, 'ai-rechnungen', 'amagno', $stepKey, $phase, $checkKey, 'profile',
            $probeKey, 'amagno_magnet_documents', '1001', 'visible', 'visible', $status, null,
            new DateTimeImmutable('2026-06-15T10:00:00+00:00'), 1, true, 1, null
        );
    }
}
