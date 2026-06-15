<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\DocumentTimelineEventRow;
use App\Intelligence\Application\DocumentTimelineProvider;
use App\Intelligence\Application\DocumentTimelineReport;
use App\Intelligence\Application\EventTimelineOrder;
use App\Intelligence\Application\ProcessTemplateCheckResultProvider;
use App\Intelligence\Application\ProcessTemplateCheckService;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateStep;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ProcessTemplateCheckResultProviderTest extends TestCase
{
    public function testComputesAvailableResultFromStoredTimeline(): void
    {
        $report = new DocumentTimelineReport('doc-1', [], [
            $this->event('A', 'after', '10:00'),
            $this->event('B', 'after', '11:00'),
        ]);
        $provider = $this->provider($this->timeline($report));

        $view = $provider->forDocument($this->template(), 'doc-1');

        self::assertTrue($view->available);
        self::assertSame('OK', $view->status);
        self::assertSame(['A', 'B'], $view->actualSteps);
        self::assertContains('A', $view->expectedSteps);
    }

    public function testReturnsUnavailableWhenServiceThrows(): void
    {
        $timeline = new class implements DocumentTimelineProvider {
            public function build(string $documentUuid, EventTimelineOrder $order = EventTimelineOrder::DEFAULT): DocumentTimelineReport
            {
                throw new RuntimeException('timeline boom');
            }
        };
        $provider = $this->provider($timeline);

        $view = $provider->forDocument($this->template(), 'doc-1');

        self::assertFalse($view->available);
        self::assertSame('timeline boom', $view->error);
    }

    private function provider(DocumentTimelineProvider $timeline): ProcessTemplateCheckResultProvider
    {
        return new ProcessTemplateCheckResultProvider(new ProcessTemplateCheckService($timeline));
    }

    private function template(): ProcessTemplate
    {
        return new ProcessTemplate(
            key: 'p',
            version: '1',
            steps: [new ProcessTemplateStep('A'), new ProcessTemplateStep('B')],
            requiredStepKeys: ['A', 'B']
        );
    }

    private function timeline(DocumentTimelineReport $report): DocumentTimelineProvider
    {
        return new class($report) implements DocumentTimelineProvider {
            public function __construct(private readonly DocumentTimelineReport $report)
            {
            }

            public function build(string $documentUuid, EventTimelineOrder $order = EventTimelineOrder::DEFAULT): DocumentTimelineReport
            {
                return $this->report;
            }
        };
    }

    private function event(string $stepKey, string $phase, string $time): DocumentTimelineEventRow
    {
        return new DocumentTimelineEventRow(
            externalEventKey: 'ext-'.$stepKey,
            eventKey: $stepKey,
            stepKey: $stepKey,
            processKey: 'p',
            documentVersion: 1,
            occurredAt: new DateTimeImmutable('2026-06-15T'.$time.':00+00:00'),
            receivedAt: new DateTimeImmutable('2026-06-15T'.$time.':05+00:00'),
            id: 1,
            processInstanceId: 1,
            contextSummary: null,
            eventPhase: $phase
        );
    }
}
