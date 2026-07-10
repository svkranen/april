<?php

namespace App\Tests\Controller\App;

use App\Intelligence\Application\DocumentTimelineEventRow;
use App\Intelligence\Application\DocumentTimelineProvider;
use App\Intelligence\Application\DocumentTimelineReport;
use App\Intelligence\Application\EventTimelineOrder;
use App\Intelligence\Application\ProcessTemplateProvider;
use App\Intelligence\Domain\ProcessTemplate;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class TemplateDraftControllerTest extends AppWebTestCase
{
    public function testDraftFormListsObservedProcessKeysAndScopes(): void
    {
        $client = self::createAuthenticatedClient();
        $this->fakeProviders($client, $this->timeline(), ['known_process']);

        $client->request('GET', '/app/intelligence/template-draft?documentUuid=doc-uuid-1');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Template-Entwurf aus einem', $html);
        self::assertStringContainsString('invoice_intake', $html);
        self::assertStringContainsString('known_process (Template bereits vorhanden)', $html);
        self::assertStringContainsString('Prozess-Template', $html);
        self::assertStringContainsString('Journey-Template', $html);
        self::assertSelectorExists('form[action="/app/intelligence/template-draft"]');
    }

    public function testProcessDraftPreviewShowsYamlMermaidAndDownloadLink(): void
    {
        $client = self::createAuthenticatedClient();
        $this->fakeProviders($client, $this->timeline(), []);

        $client->request('GET', '/app/intelligence/template-draft?documentUuid=doc-uuid-1&scope=process&templateKey=invoice_intake');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('key: invoice_intake', $html);
        self::assertStringContainsString('flowchart TD', $html);
        self::assertStringContainsString('Template-Factory: gueltig', $html);
        self::assertStringContainsString('YAML kopieren', $html);
        self::assertStringContainsString('/app/intelligence/template-draft/download', $html);
        self::assertStringContainsString('invoice_intake.yaml', $html);
    }

    public function testJourneyDraftPreviewUsesJourneyKeyField(): void
    {
        $client = self::createAuthenticatedClient();
        $this->fakeProviders($client, $this->timeline(), []);

        $client->request('GET', '/app/intelligence/template-draft?documentUuid=doc-uuid-1&scope=journey&journeyKey=my-journey');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('key: my-journey', $html);
        self::assertStringContainsString('scope: journey', $html);
        self::assertStringContainsString('my-journey.yaml', $html);
    }

    public function testUnknownDocumentShowsFriendlyNotFoundState(): void
    {
        $client = self::createAuthenticatedClient();
        $this->fakeProviders($client, new DocumentTimelineReport('empty-doc', [], []), []);

        $client->request('GET', '/app/intelligence/template-draft?documentUuid=empty-doc&scope=process&templateKey=missing_process');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Keine Events gefunden', $html);
        self::assertStringContainsString('missing_process', $html);
    }

    public function testInvalidScopeShowsInputErrorInsteadOfServerError(): void
    {
        $client = self::createAuthenticatedClient();
        $this->fakeProviders($client, $this->timeline(), []);

        $client->request('GET', '/app/intelligence/template-draft?documentUuid=doc-uuid-1&scope=case&templateKey=invoice_intake');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Unbekannter Template-Typ', (string) $client->getResponse()->getContent());
    }

    public function testMissingDocumentUuidShowsInputError(): void
    {
        $client = self::createAuthenticatedClient();
        $this->fakeProviders($client, new DocumentTimelineReport('empty-doc', [], []), []);

        $client->request('GET', '/app/intelligence/template-draft');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('gueltige Document UUID', (string) $client->getResponse()->getContent());
    }

    public function testDownloadReturnsYamlAttachment(): void
    {
        $client = self::createAuthenticatedClient();
        $this->fakeProviders($client, $this->timeline(), []);

        $client->request('GET', '/app/intelligence/template-draft/download?documentUuid=doc-uuid-1&scope=process&templateKey=invoice_intake');

        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertStringContainsString('application/x-yaml', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('invoice_intake.yaml', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('key: invoice_intake', (string) $response->getContent());
    }

    public function testDownloadWithoutDataRedirectsToPreviewPage(): void
    {
        $client = self::createAuthenticatedClient();
        $this->fakeProviders($client, new DocumentTimelineReport('empty-doc', [], []), []);

        $client->request('GET', '/app/intelligence/template-draft/download?documentUuid=empty-doc&scope=process&templateKey=missing_process');

        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/app/intelligence/template-draft?', $location);
        self::assertStringContainsString('documentUuid=empty-doc', $location);
    }

    public function testDocumentJourneyPageOffersDraftActions(): void
    {
        $client = self::createAuthenticatedClient();
        $this->fakeProviders($client, $this->timeline(), ['known_process']);

        $client->request('GET', '/app/intelligence/documents/doc-uuid-1');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Template-Entwurf erstellen', $html);
        // Direct draft link only for the process key without a known template.
        self::assertSelectorExists('a[href*="/app/intelligence/template-draft?documentUuid=doc-uuid-1"][href*="templateKey=invoice_intake"]');
        self::assertSelectorNotExists('a[href*="templateKey=known_process"]');
    }

    /**
     * @param array<int, string> $knownProcessKeys
     */
    private function fakeProviders(KernelBrowser $client, DocumentTimelineReport $timeline, array $knownProcessKeys): void
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

        $container = static::getContainer();
        $container->set(DocumentTimelineProvider::class, $timelineProvider);
        $container->set(ProcessTemplateProvider::class, $templateProvider);
    }

    private function timeline(): DocumentTimelineReport
    {
        return new DocumentTimelineReport('doc-uuid-1', [], [
            $this->event('evt-1', 'invoice_intake', 'received', '2026-06-01T09:00:00+00:00'),
            $this->event('evt-2', 'invoice_intake', 'approved', '2026-06-01T10:00:00+00:00'),
            $this->event('evt-3', 'known_process', 'archived', '2026-06-01T11:00:00+00:00'),
        ]);
    }

    private function event(string $externalEventKey, string $processKey, string $stepKey, string $occurredAt): DocumentTimelineEventRow
    {
        $time = new DateTimeImmutable($occurredAt);

        return new DocumentTimelineEventRow(
            externalEventKey: $externalEventKey,
            eventKey: $stepKey,
            stepKey: $stepKey,
            processKey: $processKey,
            documentVersion: 1,
            occurredAt: $time,
            receivedAt: $time,
            id: 1,
            processInstanceId: null,
            contextSummary: null,
            eventPhase: 'after'
        );
    }
}
