<?php

namespace App\Tests\Controller\App;

use App\Controller\App\DocumentJourneyController;
use App\Intelligence\Application\DocumentTimelineEventRow;
use App\Intelligence\Application\DocumentTimelineInstanceRow;
use App\Intelligence\Application\DocumentTimelineProvider;
use App\Intelligence\Application\DocumentTimelineReport;
use App\Intelligence\Application\EventTimelineOrder;
use App\Intelligence\Application\ProcessTemplateProvider;
use App\Intelligence\Domain\ProcessTemplate;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class DocumentJourneyControllerTest extends AppWebTestCase
{
    public function testIndexShowsSearchForm(): void
    {
        $client = self::createAuthenticatedClient();
        $this->fakeProviders($client, new DocumentTimelineReport('empty-doc', [], []), []);

        $client->request('GET', '/app/intelligence/documents');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action="/app/intelligence/documents"]');
        self::assertStringContainsString('dokumentbezogene Events auch ohne Prozess-Template', (string) $client->getResponse()->getContent());
    }

    public function testSearchRedirectsToDetailPage(): void
    {
        $client = self::createAuthenticatedClient();
        $this->fakeProviders($client, new DocumentTimelineReport('empty-doc', [], []), []);

        $client->request('GET', '/app/intelligence/documents?documentUuid=%20uuid-123%20');

        self::assertResponseRedirects('/app/intelligence/documents/uuid-123');
    }

    public function testDetailShowsDocumentEventsInstancesContextAndTemplateHints(): void
    {
        $client = self::createAuthenticatedClient();
        $controller = $this->controller($client, $this->timeline(), ['debitoren_intake']);

        $request = $this->request('/app/intelligence/documents/doc-uuid-1');
        static::getContainer()->get('request_stack')->push($request);
        $response = $controller->show('doc-uuid-1', $request);

        self::assertTrue($response->isSuccessful());
        $html = (string) $response->getContent();

        self::assertStringContainsString('doc-uuid-1', $html);
        self::assertStringContainsString('debitoren_intake', $html);
        self::assertStringContainsString('aufmass_workflow', $html);
        self::assertStringContainsString('10 Intake abgeschlossen', $html);
        self::assertStringContainsString('aufmass_eingang', $html);
        self::assertStringContainsString('document_type', $html);
        self::assertStringContainsString('aufmass', $html);
        self::assertStringContainsString('Template bekannt', $html);
        self::assertStringContainsString('kein Template bekannt', $html);
        self::assertStringContainsString('running', $html);
    }

    public function testDetailSupportsDocumentVersionFilter(): void
    {
        $client = self::createAuthenticatedClient();
        $controller = $this->controller($client, new DocumentTimelineReport('doc-uuid-1', [], [
            $this->event('source-v1', 'debitoren_intake', 'v1_step', 1, '2026-06-01T10:00:00+00:00'),
            $this->event('source-v2', 'debitoren_intake', 'v2_step', 2, '2026-06-01T11:00:00+00:00'),
        ]), ['debitoren_intake']);

        $request = $this->request('/app/intelligence/documents/doc-uuid-1?documentVersion=2');
        static::getContainer()->get('request_stack')->push($request);
        $response = $controller->show('doc-uuid-1', $request);

        self::assertTrue($response->isSuccessful());
        $html = (string) $response->getContent();
        self::assertStringContainsString('v2_step', $html);
        self::assertStringNotContainsString('v1_step', $html);
        self::assertStringContainsString('Dokumentversion', $html);
    }

    public function testDetailEmptyTimelineShowsFriendlyState(): void
    {
        $client = self::createAuthenticatedClient();
        $controller = $this->controller($client, new DocumentTimelineReport('empty-doc', [], []), []);

        $request = $this->request('/app/intelligence/documents/empty-doc');
        static::getContainer()->get('request_stack')->push($request);
        $response = $controller->show('empty-doc', $request);

        self::assertTrue($response->isSuccessful());
        self::assertStringContainsString('noch keine Events oder Prozessinstanzen', (string) $response->getContent());
    }

    public function testNavigationContainsDocumentsLink(): void
    {
        $client = self::createAuthenticatedClient();
        $this->fakeProviders($client, new DocumentTimelineReport('empty-doc', [], []), []);

        $client->request('GET', '/app/intelligence/documents');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('nav.app-nav a[href="/app/intelligence/documents"]');
        self::assertSelectorExists('a.brand[href="/app"]');
    }

    /**
     * @param array<int, string> $knownProcessKeys
     */
    private function fakeProviders(KernelBrowser $client, DocumentTimelineReport $timeline, array $knownProcessKeys): void
    {
        [$timelineProvider, $templateProvider] = $this->providers($timeline, $knownProcessKeys);

        $container = static::getContainer();
        $container->set(DocumentTimelineProvider::class, $timelineProvider);
        $container->set(ProcessTemplateProvider::class, $templateProvider);
    }

    /**
     * @param array<int, string> $knownProcessKeys
     */
    private function controller(KernelBrowser $client, DocumentTimelineReport $timeline, array $knownProcessKeys): DocumentJourneyController
    {
        [$timelineProvider, $templateProvider] = $this->providers($timeline, $knownProcessKeys);

        return new DocumentJourneyController(
            $timelineProvider,
            $templateProvider,
            static::getContainer()->get('twig')
        );
    }

    /**
     * @param array<int, string> $knownProcessKeys
     * @return array{0: DocumentTimelineProvider, 1: ProcessTemplateProvider}
     */
    private function providers(DocumentTimelineReport $timeline, array $knownProcessKeys): array
    {
        $timelineProvider = new class($timeline) implements DocumentTimelineProvider {
            public function __construct(private readonly DocumentTimelineReport $timeline)
            {
            }

            public function build(string $documentUuid, EventTimelineOrder $order = EventTimelineOrder::DEFAULT): DocumentTimelineReport
            {
                return $this->timeline;
            }
        };

        $templateProvider = new class($knownProcessKeys) implements ProcessTemplateProvider {
            /** @param array<int, string> $knownProcessKeys */
            public function __construct(private readonly array $knownProcessKeys)
            {
            }

            public function findByProcessKey(string $processKey): ?ProcessTemplate
            {
                return in_array($processKey, $this->knownProcessKeys, true)
                    ? new ProcessTemplate($processKey)
                    : null;
            }
        };

        return [$timelineProvider, $templateProvider];
    }

    private function timeline(): DocumentTimelineReport
    {
        return new DocumentTimelineReport('doc-uuid-1', [
            new DocumentTimelineInstanceRow(7, 'debitoren_intake', 1, '10 Intake abgeschlossen', 'running'),
            new DocumentTimelineInstanceRow(8, 'aufmass_workflow', 1, 'aufmass_eingang', 'running'),
        ], [
            new DocumentTimelineEventRow(
                externalEventKey: 'source-route',
                eventKey: 'document_routed_to_workflow',
                stepKey: '10 Intake abgeschlossen',
                processKey: 'debitoren_intake',
                documentVersion: 1,
                occurredAt: new DateTimeImmutable('2026-06-01T10:00:00+00:00'),
                receivedAt: new DateTimeImmutable('2026-06-01T10:00:01+00:00'),
                id: 1,
                processInstanceId: 7,
                contextSummary: [
                    'attributes' => ['document_type' => 'aufmass'],
                    'warnings' => [],
                    'source' => 'snapshot',
                ],
                eventPhase: 'after'
            ),
            $this->event('target-start', 'aufmass_workflow', 'aufmass_eingang', 1, '2026-06-01T10:05:00+00:00'),
        ]);
    }

    private function request(string $uri): Request
    {
        $request = Request::create($uri);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    private function event(string $externalEventKey, string $processKey, string $stepKey, int $documentVersion, string $occurredAt): DocumentTimelineEventRow
    {
        $time = new DateTimeImmutable($occurredAt);

        return new DocumentTimelineEventRow(
            externalEventKey: $externalEventKey,
            eventKey: $stepKey,
            stepKey: $stepKey,
            processKey: $processKey,
            documentVersion: $documentVersion,
            occurredAt: $time,
            receivedAt: $time,
            id: 2,
            processInstanceId: null,
            contextSummary: null,
            eventPhase: 'after'
        );
    }
}
