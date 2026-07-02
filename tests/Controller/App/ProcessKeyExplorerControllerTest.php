<?php

namespace App\Tests\Controller\App;

use App\Intelligence\Application\DocumentTimelineProvider;
use App\Intelligence\Application\DocumentTimelineReport;
use App\Intelligence\Application\EventTimelineOrder;
use App\Intelligence\Application\ProcessKeyDocumentOverviewRow;
use App\Intelligence\Application\ProcessKeyOverviewProvider;
use App\Intelligence\Application\ProcessKeyOverviewRow;
use App\Intelligence\Application\ProcessTemplateProvider;
use App\Intelligence\Domain\ProcessTemplate;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class ProcessKeyExplorerControllerTest extends AppWebTestCase
{
    public function testProcessKeyOverviewListsKnownProcessKeysAndTemplateBadges(): void
    {
        $client = self::createAuthenticatedClient();
        $processKey = 'debitoren.intake_v1-foo:bar';
        $this->fakeProviders(
            $client,
            [
                new ProcessKeyOverviewRow($processKey, 2, 7),
                new ProcessKeyOverviewRow('unknown_process', 1, 3),
            ],
            [],
            [$processKey]
        );

        $client->request('GET', '/app/intelligence/process-keys');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Verwendete Process Keys', $html);
        self::assertStringContainsString($processKey, $html);
        self::assertStringContainsString('unknown_process', $html);
        self::assertStringContainsString('Template bekannt', $html);
        self::assertStringContainsString('kein Template bekannt', $html);
        self::assertStringContainsString('>7<', $html);
        self::assertSelectorExists(sprintf('a[href="/app/intelligence/process-keys/%s/documents"]', $processKey));
    }

    public function testProcessKeyDocumentsListLinksToDocumentJourneyWithVersion(): void
    {
        $client = self::createAuthenticatedClient();
        $processKey = 'debitoren.intake_v1-foo:bar';
        $this->fakeProviders(
            $client,
            [],
            [
                $processKey => [
                    new ProcessKeyDocumentOverviewRow(
                        'doc-uuid-1',
                        'EXT-99',
                        2,
                        4,
                        new DateTimeImmutable('2026-06-01T10:00:00+00:00'),
                        new DateTimeImmutable('2026-06-01T11:30:00+00:00')
                    ),
                ],
            ],
            []
        );

        $client->request('GET', '/app/intelligence/process-keys/'.$processKey.'/documents');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('doc-uuid-1', $html);
        self::assertStringContainsString('EXT-99', $html);
        self::assertStringContainsString('kein Template bekannt', $html);
        self::assertStringContainsString('2026-06-01 10:00', $html);
        self::assertStringContainsString('2026-06-01 11:30', $html);
        self::assertSelectorExists('a[href="/app/intelligence/documents/doc-uuid-1?documentVersion=2"]');
    }

    public function testProcessKeyDocumentsListCanLinkWithoutVersion(): void
    {
        $client = self::createAuthenticatedClient();
        $this->fakeProviders(
            $client,
            [],
            [
                'without_version' => [
                    new ProcessKeyDocumentOverviewRow('doc-uuid-2', null, null, 1, null, null),
                ],
            ],
            ['without_version']
        );

        $client->request('GET', '/app/intelligence/process-keys/without_version/documents');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/app/intelligence/documents/doc-uuid-2"]');
        self::assertStringContainsString('Template bekannt', (string) $client->getResponse()->getContent());
    }

    public function testProcessKeyOverviewEmptyState(): void
    {
        $client = self::createAuthenticatedClient();
        $this->fakeProviders($client, [], [], []);

        $client->request('GET', '/app/intelligence/process-keys');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('noch keine Events mit Process Key', (string) $client->getResponse()->getContent());
    }

    public function testDocumentExplorerIndexLinksToProcessKeyOverview(): void
    {
        $client = self::createAuthenticatedClient();
        $this->fakeProviders($client, [], [], []);

        $client->request('GET', '/app/intelligence/documents');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/app/intelligence/process-keys"]');
        self::assertStringContainsString('Verwendete Process Keys', (string) $client->getResponse()->getContent());
    }

    /**
     * @param array<int, ProcessKeyOverviewRow> $processKeys
     * @param array<string, array<int, ProcessKeyDocumentOverviewRow>> $documentsByProcessKey
     * @param array<int, string> $knownProcessKeys
     */
    private function fakeProviders(
        KernelBrowser $client,
        array $processKeys,
        array $documentsByProcessKey,
        array $knownProcessKeys
    ): void {
        $client->disableReboot();

        $overviewProvider = new class($processKeys, $documentsByProcessKey) implements ProcessKeyOverviewProvider {
            /**
             * @param array<int, ProcessKeyOverviewRow> $processKeys
             * @param array<string, array<int, ProcessKeyDocumentOverviewRow>> $documentsByProcessKey
             */
            public function __construct(
                private readonly array $processKeys,
                private readonly array $documentsByProcessKey
            ) {
            }

            public function processKeys(): array
            {
                return $this->processKeys;
            }

            public function documentsForProcessKey(string $processKey, ?int $limit = null): array
            {
                return $this->documentsByProcessKey[$processKey] ?? [];
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

        $timelineProvider = new class implements DocumentTimelineProvider {
            public function build(string $documentUuid, EventTimelineOrder $order = EventTimelineOrder::DEFAULT): DocumentTimelineReport
            {
                return new DocumentTimelineReport($documentUuid, [], []);
            }
        };

        $container = static::getContainer();
        $container->set(ProcessKeyOverviewProvider::class, $overviewProvider);
        $container->set(ProcessTemplateProvider::class, $templateProvider);
        $container->set(DocumentTimelineProvider::class, $timelineProvider);
    }
}
