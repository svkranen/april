<?php

namespace App\Tests\Controller\App;

use App\Intelligence\Application\DocumentListProvider;
use App\Intelligence\Application\DocumentListRow;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class TemplateDocumentsTest extends WebTestCase
{
    public function testDocumentsPageEmptyStateReturns200(): void
    {
        $client = static::createClient();
        $this->fakeDocuments($client, []);

        $client->request('GET', '/app/templates/ai-rechnungen/documents');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('APRIL bereits Events', $html);
        self::assertStringContainsString('keine Dokumente bekannt', $html);
    }

    public function testDocumentsPageListsKnownDocument(): void
    {
        $client = static::createClient();
        $this->fakeDocuments($client, [
            new DocumentListRow('uuid-known-123', 'DOC-77', 4, 6, new DateTimeImmutable('2026-06-15T09:30:00+00:00')),
        ]);

        $client->request('GET', '/app/templates/ai-rechnungen/documents');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('uuid-known-123', $html);
        self::assertStringContainsString('APRIL bereits Events', $html);
    }

    public function testUnknownTemplateReturns404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/app/templates/does-not-exist-xyz/documents');

        self::assertResponseStatusCodeSame(404);
    }

    public function testDetailPageHasActiveDocumentsLink(): void
    {
        $client = static::createClient();
        $client->request('GET', '/app/templates/ai-rechnungen');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a.pill-link[href="/app/templates/ai-rechnungen/documents"]');
    }

    /**
     * @param array<int, DocumentListRow> $rows
     */
    private function fakeDocuments(KernelBrowser $client, array $rows): void
    {
        $fake = new class($rows) implements DocumentListProvider {
            /** @param array<int, DocumentListRow> $rows */
            public function __construct(private readonly array $rows)
            {
            }

            public function documentsForProcess(string $processKey, ?int $limit = null): array
            {
                return $this->rows;
            }
        };

        // Override the provider so the controller never hits the database
        // (no test DB exists). The interface id is what the controller receives.
        static::getContainer()->set(DocumentListProvider::class, $fake);
    }
}
