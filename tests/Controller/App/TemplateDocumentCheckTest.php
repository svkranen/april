<?php

namespace App\Tests\Controller\App;

use App\Intelligence\Application\DocumentCheckResultProvider;
use App\Intelligence\Application\DocumentCheckResultView;
use App\Intelligence\Application\DocumentTimelineProvider;
use App\Intelligence\Application\DocumentTimelineReport;
use App\Intelligence\Application\EventTimelineOrder;
use App\Intelligence\Application\ProcessTemplateCheckResult;
use App\Intelligence\Application\VisibilityCheckResultProvider;
use App\Intelligence\Domain\ProcessTemplate;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class TemplateDocumentCheckTest extends WebTestCase
{
    private const URL = '/app/templates/ai-rechnungen/documents/doc-1';

    public function testCheckSectionShowsStatusStepsAndDeviations(): void
    {
        $client = static::createClient();
        $check = DocumentCheckResultView::fromResult(new ProcessTemplateCheckResult(
            ['01 Rechnungseingang', '02 Freigabe'],
            ['01 Rechnungseingang'],
            ['Pflichtschritt 02 Freigabe fehlt'],
            [],
            ['Context fehlt: standort'],
            null,
            []
        ));
        $this->fakeProviders($client, $check);

        $client->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Soll-/Ist-Prüfung', $html);
        self::assertStringContainsString('nicht als Finding persistiert', $html); // on-demand hint
        self::assertStringContainsString('DEVIATION', $html); // status badge
        self::assertStringContainsString('01 Rechnungseingang', $html); // required + actual step
        self::assertStringContainsString('02 Freigabe', $html); // required step
        self::assertStringContainsString('Pflichtschritt 02 Freigabe fehlt', $html); // deviation
        self::assertStringContainsString('Context fehlt: standort', $html); // warning
    }

    public function testCheckUnavailableShowsHintAndStays200(): void
    {
        $client = static::createClient();
        $this->fakeProviders($client, DocumentCheckResultView::unavailable('decision field missing stability'));

        $client->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Soll-/Ist-Prüfung', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('nicht berechenbar', (string) $client->getResponse()->getContent());
    }

    private function fakeProviders(KernelBrowser $client, DocumentCheckResultView $checkView): void
    {
        $timeline = new class implements DocumentTimelineProvider {
            public function build(string $documentUuid, EventTimelineOrder $order = EventTimelineOrder::DEFAULT): DocumentTimelineReport
            {
                return new DocumentTimelineReport($documentUuid, [], []);
            }
        };

        $visibility = new class implements VisibilityCheckResultProvider {
            public function findByDocument(string $documentUuid, ?string $processKey = null): array
            {
                return [];
            }
        };

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
}
