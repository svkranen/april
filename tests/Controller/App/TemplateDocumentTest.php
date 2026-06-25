<?php

namespace App\Tests\Controller\App;

use App\Intelligence\Application\DocumentCheckResultProvider;
use App\Intelligence\Application\DocumentCheckResultView;
use App\Intelligence\Application\DocumentTimelineEventRow;
use App\Intelligence\Application\DocumentTimelineProvider;
use App\Intelligence\Application\DocumentTimelineReport;
use App\Intelligence\Application\EventTimelineOrder;
use App\Intelligence\Application\ProcessTemplateCheckResult;
use App\Intelligence\Application\VisibilityCheckResultProvider;
use App\Intelligence\Application\VisibilityCheckResultRecord;
use App\Intelligence\Domain\ProcessTemplate;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class TemplateDocumentTest extends WebTestCase
{
    private const UUID = 'doc-uuid-xyz';

    public function testDocumentDetailReturns200WithTimelineAndVisibility(): void
    {
        $client = static::createClient();
        $this->fakeProviders(
            $client,
            $this->timelineWithEvents(),
            [$this->record('03 Visibility-Step', 'after', 'route', 'external_today', 'violation')]
        );

        $client->request('GET', '/app/templates/ai-rechnungen/documents/'.self::UUID);

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString(self::UUID, $html);
        self::assertStringContainsString('gespeicherten Events', $html); // hint
        // The business step "01 Rechnungseingang" appears exactly once - before/after
        // are phases inside it, not separate process steps.
        self::assertSame(1, substr_count($html, 'step-head">01 Rechnungseingang'));
        self::assertStringContainsString('phase-label">before', $html);
        self::assertStringContainsString('phase-label">after', $html);
        // Visibility result is shown with its status.
        self::assertStringContainsString('external_today', $html);
        self::assertStringContainsString('violation', $html);
    }

    public function testEmptyTimelineState(): void
    {
        $client = static::createClient();
        $this->fakeProviders($client, new DocumentTimelineReport(self::UUID, [], []), []);

        $client->request('GET', '/app/templates/ai-rechnungen/documents/'.self::UUID);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('noch keine Events', (string) $client->getResponse()->getContent());
    }

    public function testNoVisibilityResultsHint(): void
    {
        $client = static::createClient();
        $this->fakeProviders($client, $this->timelineWithEvents(), []);

        $client->request('GET', '/app/templates/ai-rechnungen/documents/'.self::UUID);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('noch keine gespeicherten Access-/Visibility-CheckResults', (string) $client->getResponse()->getContent());
    }

    public function testUnknownTemplateReturns404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/app/templates/does-not-exist-xyz/documents/'.self::UUID);

        self::assertResponseStatusCodeSame(404);
    }

    public function testBusinessViewHidesTechnicalDetails(): void
    {
        $client = static::createClient();
        $this->fakeProviders($client, $this->timelineWithEvents(), []);
        $client->request('GET', '/app/templates/ai-rechnungen/documents/'.self::UUID.'?view=business');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('externalEventKey=', (string) $client->getResponse()->getContent());
    }

    public function testExpertViewShowsTechnicalDetails(): void
    {
        $client = static::createClient();
        $this->fakeProviders($client, $this->timelineWithEvents(), []);
        $client->request('GET', '/app/templates/ai-rechnungen/documents/'.self::UUID.'?view=expert');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('externalEventKey=', (string) $client->getResponse()->getContent());
    }

    private function timelineWithEvents(): DocumentTimelineReport
    {
        return new DocumentTimelineReport(self::UUID, [], [
            $this->event('01 Rechnungseingang', 'before', 'eingang.before', '10:00'),
            $this->event('01 Rechnungseingang', 'after', 'eingang.after', '10:01'),
        ]);
    }

    /**
     * @param array<int, VisibilityCheckResultRecord> $records
     */
    private function fakeProviders(KernelBrowser $client, DocumentTimelineReport $report, array $records): void
    {
        $timeline = new class($report) implements DocumentTimelineProvider {
            public function __construct(private readonly DocumentTimelineReport $report)
            {
            }

            public function build(string $documentUuid, EventTimelineOrder $order = EventTimelineOrder::DEFAULT): DocumentTimelineReport
            {
                return $this->report;
            }
        };

        $visibility = new class($records) implements VisibilityCheckResultProvider {
            /** @param array<int, VisibilityCheckResultRecord> $records */
            public function __construct(private readonly array $records)
            {
            }

            public function findByDocument(string $documentUuid, ?string $processKey = null): array
            {
                return $this->records;
            }
        };

        // Benign on-demand check result keeps these tests focused on timeline/visibility.
        $checkView = DocumentCheckResultView::fromResult(new ProcessTemplateCheckResult([], [], []));
        $check = new class($checkView) implements DocumentCheckResultProvider {
            public function __construct(private readonly DocumentCheckResultView $view)
            {
            }

            public function forDocument(ProcessTemplate $template, string $documentUuid): DocumentCheckResultView
            {
                return $this->view;
            }
        };

        $container = static::getContainer();
        $container->set(DocumentTimelineProvider::class, $timeline);
        $container->set(VisibilityCheckResultProvider::class, $visibility);
        $container->set(DocumentCheckResultProvider::class, $check);
    }

    private function event(string $stepKey, string $phase, string $eventKey, string $time): DocumentTimelineEventRow
    {
        return new DocumentTimelineEventRow(
            externalEventKey: 'ext-'.$eventKey,
            eventKey: $eventKey,
            stepKey: $stepKey,
            processKey: 'ai-rechnungen',
            documentVersion: 1,
            occurredAt: new DateTimeImmutable('2026-06-15T'.$time.':00+00:00'),
            receivedAt: new DateTimeImmutable('2026-06-15T'.$time.':05+00:00'),
            id: 10,
            processInstanceId: 5,
            contextSummary: null,
            eventPhase: $phase
        );
    }

    private function record(string $stepKey, string $phase, string $checkKey, string $probeKey, string $status): VisibilityCheckResultRecord
    {
        return new VisibilityCheckResultRecord(
            1, self::UUID, 1, 'ai-rechnungen', 'amagno', $stepKey, $phase, $checkKey, 'profile',
            $probeKey, 'amagno_magnet_documents', '1009', 'hidden', 'visible', $status, 'forbidden_visibility',
            new DateTimeImmutable('2026-06-15T10:00:00+00:00'), 1, true, 1, null
        );
    }
}
