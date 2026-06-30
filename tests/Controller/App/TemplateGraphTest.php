<?php

namespace App\Tests\Controller\App;

use App\Intelligence\Application\DocumentCheckResultProvider;
use App\Intelligence\Application\DocumentCheckResultView;
use App\Intelligence\Application\DocumentListProvider;
use App\Intelligence\Application\DocumentListRow;
use App\Intelligence\Application\ProcessTemplateCheckResult;
use App\Intelligence\Application\VisibilityCheckResultProvider;
use App\Intelligence\Application\VisibilityCheckResultRecord;
use App\Intelligence\Domain\ProcessTemplate;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

class TemplateGraphTest extends AppWebTestCase
{
    private const STEP = '01 Rechnungen pruefen';

    public function testGraphPageRendersMermaidSourceWithoutFindings(): void
    {
        $client = self::createAuthenticatedClient();

        // No DocumentListProvider fake: a 200 here proves no documents were read
        // (the real provider would hit a non-existent test DB).
        $client->request('GET', '/app/templates/ai-rechnungen/graph');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('flowchart TD', $html);
        self::assertStringContainsString('n_01_Rechnungen_pruefen', $html);
        // Opt-in: every node is not_calculated and the activation link is offered.
        self::assertStringContainsString('class n_01_Rechnungen_pruefen not_calculated', $html);
        self::assertSelectorExists('a.pill-link[href="/app/templates/ai-rechnungen/graph?withFindings=1"]');
    }

    public function testGraphPageAggregatesFindingsPerStepWithOptIn(): void
    {
        $client = self::createAuthenticatedClient();
        $this->fakeProviders(
            $client,
            [new DocumentListRow('doc-1', null, 1, 3, new DateTimeImmutable('2026-06-15T09:30:00+00:00'))],
            [$this->record(self::STEP, 'violation')],
            DocumentCheckResultView::fromResult(new ProcessTemplateCheckResult([], [], []))
        );

        $client->request('GET', '/app/templates/ai-rechnungen/graph?withFindings=1');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('class n_01_Rechnungen_pruefen critical', $html);
        self::assertStringContainsString('Kritisch', $html);
    }

    public function testUnknownTemplateReturns404(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/templates/does-not-exist-xyz/graph');

        self::assertResponseStatusCodeSame(404);
    }

    public function testDetailPageLinksToGraph(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/templates/ai-rechnungen');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a.pill-link[href="/app/templates/ai-rechnungen/graph"]');
    }

    /**
     * @param array<int, DocumentListRow> $rows
     * @param array<int, VisibilityCheckResultRecord> $records
     */
    private function fakeProviders(KernelBrowser $client, array $rows, array $records, DocumentCheckResultView $check): void
    {
        $documents = new class($rows) implements DocumentListProvider {
            /** @param array<int, DocumentListRow> $rows */
            public function __construct(private readonly array $rows)
            {
            }

            public function documentsForProcess(string $processKey, ?int $limit = null): array
            {
                return $this->rows;
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
        $container->set(DocumentListProvider::class, $documents);
        $container->set(VisibilityCheckResultProvider::class, $visibility);
        $container->set(DocumentCheckResultProvider::class, $checkProvider);
    }

    private function record(string $stepKey, string $status): VisibilityCheckResultRecord
    {
        return new VisibilityCheckResultRecord(
            1, 'doc-1', 1, 'ai-rechnungen', 'amagno', $stepKey, 'after', 'route', 'profile',
            'external_today', 'amagno_magnet_documents', '1009', 'hidden', 'visible', $status, 'forbidden_visibility',
            new DateTimeImmutable('2026-06-15T10:00:00+00:00'), 1, true, 1, null
        );
    }
}
