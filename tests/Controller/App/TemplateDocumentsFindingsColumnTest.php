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
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

class TemplateDocumentsFindingsColumnTest extends AppWebTestCase
{
    private const BASE = '/app/templates/ai-rechnungen/documents';

    public function testWithoutFindingsShowsNotComputedAndActivationLink(): void
    {
        $client = self::createAuthenticatedClient();
        $this->fakes($client, [$this->row('uuid-1')], $this->okCheck(), []);

        $client->request('GET', self::BASE);

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('nicht berechnet', $html);
        self::assertStringContainsString('nicht automatisch berechnet', $html);
        self::assertSelectorExists('a[href="/app/templates/ai-rechnungen/documents?withFindings=1"]');
    }

    public function testWithFindingsAccessViolationShowsKritisch(): void
    {
        $client = self::createAuthenticatedClient();
        $this->fakes($client, [$this->row('uuid-1')], $this->okCheck(), [$this->record('violation')]);

        $client->request('GET', self::BASE.'?withFindings=1');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Kritisch', $html);
        self::assertStringContainsString('on-demand für diese Liste berechnet', $html);
    }

    public function testWithFindingsProcessDeviationShowsAbweichung(): void
    {
        $client = self::createAuthenticatedClient();
        $check = DocumentCheckResultView::fromResult(new ProcessTemplateCheckResult(['01', '02'], ['01'], ['fehlt 02'], [], [], null, []));
        $this->fakes($client, [$this->row('uuid-1')], $check, []);

        $client->request('GET', self::BASE.'?withFindings=1');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Abweichung', (string) $client->getResponse()->getContent());
    }

    public function testCheckErrorDoesNotCause500AndShowsTechnical(): void
    {
        $client = self::createAuthenticatedClient();

        $visibility = new class implements VisibilityCheckResultProvider {
            public function findByDocument(string $documentUuid, ?string $processKey = null): array
            {
                throw new RuntimeException('boom');
            }
        };
        $this->setProviders($client, [$this->row('uuid-1')], $this->okCheck(), $visibility);

        $client->request('GET', self::BASE.'?withFindings=1');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Technisch', (string) $client->getResponse()->getContent());
    }

    public function testLimitHintShownWhenMoreThanLimitDocuments(): void
    {
        $client = self::createAuthenticatedClient();
        $rows = [];
        for ($i = 0; $i < 51; $i++) {
            $rows[] = $this->row('uuid-'.$i);
        }
        $this->fakes($client, $rows, $this->okCheck(), []);

        $client->request('GET', self::BASE.'?withFindings=1');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('nur für die ersten 50 Items', (string) $client->getResponse()->getContent());
    }

    public function testBusinessViewHidesBreakdown(): void
    {
        $client = self::createAuthenticatedClient();
        $this->fakes($client, [$this->row('uuid-1')], $this->okCheck(), [$this->record('violation')]);
        $client->request('GET', self::BASE.'?withFindings=1&view=business');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Breakdown:', (string) $client->getResponse()->getContent());
    }

    public function testExpertViewShowsBreakdown(): void
    {
        $client = self::createAuthenticatedClient();
        $this->fakes($client, [$this->row('uuid-1')], $this->okCheck(), [$this->record('violation')]);
        $client->request('GET', self::BASE.'?withFindings=1&view=expert');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Breakdown:', (string) $client->getResponse()->getContent());
    }

    private function okCheck(): DocumentCheckResultView
    {
        return DocumentCheckResultView::fromResult(new ProcessTemplateCheckResult([], [], []));
    }

    /**
     * @param array<int, DocumentListRow> $rows
     * @param array<int, VisibilityCheckResultRecord> $records
     */
    private function fakes(KernelBrowser $client, array $rows, DocumentCheckResultView $check, array $records): void
    {
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

        $this->setProviders($client, $rows, $check, $visibility);
    }

    /**
     * @param array<int, DocumentListRow> $rows
     */
    private function setProviders(KernelBrowser $client, array $rows, DocumentCheckResultView $check, VisibilityCheckResultProvider $visibility): void
    {
        $list = new class($rows) implements DocumentListProvider {
            /** @param array<int, DocumentListRow> $rows */
            public function __construct(private readonly array $rows)
            {
            }

            public function documentsForProcess(string $processKey, ?int $limit = null): array
            {
                return $this->rows;
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
        $container->set(DocumentListProvider::class, $list);
        $container->set(DocumentCheckResultProvider::class, $checkProvider);
        $container->set(VisibilityCheckResultProvider::class, $visibility);
    }

    private function row(string $uuid): DocumentListRow
    {
        return new DocumentListRow($uuid, 'DOC', 1, 3, new DateTimeImmutable('2026-06-15T09:00:00+00:00'));
    }

    private function record(string $status): VisibilityCheckResultRecord
    {
        return new VisibilityCheckResultRecord(
            1, 'uuid-1', 1, 'ai-rechnungen', 'amagno', '01', 'after', 'route', 'profile',
            'external_today', 'amagno_magnet_documents', '1009', 'hidden', 'visible', $status, 'forbidden_visibility',
            new DateTimeImmutable('2026-06-15T10:00:00+00:00'), 1, true, 1, null
        );
    }
}
