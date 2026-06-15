<?php

namespace App\Tests\Controller\App;

use App\Intelligence\Application\DocumentCheckResultProvider;
use App\Intelligence\Application\DocumentCheckResultView;
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

class TemplateDocumentFindingsTest extends WebTestCase
{
    private const URL = '/app/templates/ai-rechnungen/documents/doc-1';

    public function testPanelShowsOkWhenNoFindings(): void
    {
        $client = static::createClient();
        $this->fakeProviders($client, $this->okCheck(), []);

        $client->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Zusammenfassung', $html);
        self::assertStringContainsString('unauffällig', $html);
    }

    public function testAccessViolationShowsCriticalInPanel(): void
    {
        $client = static::createClient();
        $this->fakeProviders($client, $this->okCheck(), [$this->record('violation')]);

        $client->request('GET', self::URL.'?view=business');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Zusammenfassung', $html);
        self::assertStringContainsString('Kritisch', $html);
        self::assertStringContainsString('Verbotene Sichtbarkeit', $html);
        // Business view does not expose technical keys/reasons.
        self::assertStringNotContainsString('probeKey=', $html);
        self::assertStringNotContainsString('reason=', $html);
    }

    public function testExpertViewShowsTechnicalKeysInPanel(): void
    {
        $client = static::createClient();
        $this->fakeProviders($client, $this->okCheck(), [$this->record('violation')]);

        $client->request('GET', self::URL.'?view=expert');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('probeKey=', $html);
        self::assertStringContainsString('reason=', $html);
    }

    public function testProcessDeviationShowsAbweichungInPanel(): void
    {
        $client = static::createClient();
        $check = DocumentCheckResultView::fromResult(new ProcessTemplateCheckResult(
            ['01', '02'], ['01'], ['Pflichtschritt 02 fehlt'], [], [], null, []
        ));
        $this->fakeProviders($client, $check, []);

        $client->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Abweichung', (string) $client->getResponse()->getContent());
    }

    private function okCheck(): DocumentCheckResultView
    {
        return DocumentCheckResultView::fromResult(new ProcessTemplateCheckResult([], [], []));
    }

    /**
     * @param array<int, VisibilityCheckResultRecord> $records
     */
    private function fakeProviders(KernelBrowser $client, DocumentCheckResultView $check, array $records): void
    {
        $timeline = new class implements DocumentTimelineProvider {
            public function build(string $documentUuid, EventTimelineOrder $order = EventTimelineOrder::DEFAULT): DocumentTimelineReport
            {
                return new DocumentTimelineReport($documentUuid, [], []);
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
        $checkProvider = new class($check) implements DocumentCheckResultProvider {
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
        $container->set(DocumentCheckResultProvider::class, $checkProvider);
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
