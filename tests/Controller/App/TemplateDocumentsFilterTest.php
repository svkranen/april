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

class TemplateDocumentsFilterTest extends AppWebTestCase
{
    private const BASE = '/app/templates/ai-rechnungen/documents';

    public function testWithoutFindingsShowsFilterHint(): void
    {
        $client = self::createAuthenticatedClient();
        $this->fakes($client, [$this->row('doc-1')], [], []);

        $client->request('GET', self::BASE);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Severity-Filter erst verfügbar', (string) $client->getResponse()->getContent());
    }

    public function testFilterCriticalShowsOnlyCriticalDocuments(): void
    {
        $client = self::createAuthenticatedClient();
        // doc-crit -> access violation; doc-ok -> nothing.
        $this->fakes($client, [$this->row('doc-crit'), $this->row('doc-ok')], [], ['doc-crit' => [$this->record('violation')]]);

        $client->request('GET', self::BASE.'?withFindings=1&severity=critical');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('doc-crit', $html);
        self::assertStringNotContainsString('doc-ok', $html);
        self::assertStringContainsString('Filter: Kritisch', $html);
        self::assertStringContainsString('Zeige 1 von 2 Items', $html);
    }

    public function testFilterDeviationShowsOnlyDeviationDocuments(): void
    {
        $client = self::createAuthenticatedClient();
        $deviationCheck = DocumentCheckResultView::fromResult(new ProcessTemplateCheckResult(['01', '02'], ['01'], ['fehlt 02'], [], [], null, []));
        $this->fakes($client, [$this->row('doc-dev'), $this->row('doc-ok')], ['doc-dev' => $deviationCheck], []);

        $client->request('GET', self::BASE.'?withFindings=1&severity=deviation');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('doc-dev', $html);
        self::assertStringNotContainsString('doc-ok', $html);
    }

    public function testFilterWarningAndTechnical(): void
    {
        $client = self::createAuthenticatedClient();
        $this->fakes($client, [$this->row('doc-warn'), $this->row('doc-tech')], [], [
            'doc-warn' => [$this->record('warning')],
            'doc-tech' => [$this->record('unknown')],
        ]);
        $client->request('GET', self::BASE.'?withFindings=1&severity=warning');
        self::assertStringContainsString('doc-warn', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('doc-tech', (string) $client->getResponse()->getContent());
    }

    public function testFilterOkShowsUnremarkableDocuments(): void
    {
        $client = self::createAuthenticatedClient();
        $this->fakes($client, [$this->row('doc-ok'), $this->row('doc-crit')], [], ['doc-crit' => [$this->record('violation')]]);

        $client->request('GET', self::BASE.'?withFindings=1&severity=ok');

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('doc-ok', $html);
        self::assertStringNotContainsString('doc-crit', $html);
    }

    public function testInvalidSeverityNormalisesToAll(): void
    {
        $client = self::createAuthenticatedClient();
        $this->fakes($client, [$this->row('doc-1'), $this->row('doc-2')], [], ['doc-1' => [$this->record('violation')]]);

        $client->request('GET', self::BASE.'?withFindings=1&severity=bogus');

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('doc-1', $html);
        self::assertStringContainsString('doc-2', $html);
        self::assertStringContainsString('Zeige 2 von 2 Items', $html);
    }

    public function testActiveFilterIsMarkedAndLinksCarryWithFindings(): void
    {
        $client = self::createAuthenticatedClient();
        $this->fakes($client, [$this->row('doc-1')], [], ['doc-1' => [$this->record('violation')]]);

        $client->request('GET', self::BASE.'?withFindings=1&severity=critical');

        self::assertSelectorTextContains('a.pill-link.is-active', 'Kritisch');
        self::assertSelectorExists('a[href="/app/templates/ai-rechnungen/documents?withFindings=1&severity=warning"]');
    }

    public function testEmptyStateWhenFilterMatchesNothing(): void
    {
        $client = self::createAuthenticatedClient();
        $this->fakes($client, [$this->row('doc-ok')], [], []);

        $client->request('GET', self::BASE.'?withFindings=1&severity=critical');

        self::assertStringContainsString('Keine Items für diesen Filter gefunden', (string) $client->getResponse()->getContent());
    }

    public function testNotCalculatedFilterShowsOnlyLimitRows(): void
    {
        $client = self::createAuthenticatedClient();
        $rows = [];
        for ($i = 0; $i < 51; $i++) {
            $rows[] = $this->row('uuid-'.$i);
        }
        $this->fakes($client, $rows, [], []); // all computed rows -> ok

        $client->request('GET', self::BASE.'?withFindings=1&severity=not_calculated');

        $html = (string) $client->getResponse()->getContent();
        // Only the 51st row (index 50) is beyond the limit and uncomputed.
        self::assertStringContainsString('uuid-50', $html);
        self::assertStringNotContainsString('>uuid-0<', $html);
        self::assertStringContainsString('Zeige 1 von 51 Items', $html);
    }

    /**
     * @param array<int, DocumentListRow> $rows
     * @param array<string, DocumentCheckResultView> $checkMap
     * @param array<string, array<int, VisibilityCheckResultRecord>> $visibilityMap
     */
    private function fakes(KernelBrowser $client, array $rows, array $checkMap, array $visibilityMap): void
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

        $okView = DocumentCheckResultView::fromResult(new ProcessTemplateCheckResult([], [], []));
        $checkProvider = new class($okView, $checkMap) implements DocumentCheckResultProvider {
            /** @param array<string, DocumentCheckResultView> $map */
            public function __construct(private readonly DocumentCheckResultView $default, private readonly array $map)
            {
            }

            public function forDocument(ProcessTemplate $template, string $documentUuid): DocumentCheckResultView
            {
                return $this->map[$documentUuid] ?? $this->default;
            }
        };

        $visibility = new class($visibilityMap) implements VisibilityCheckResultProvider {
            /** @param array<string, array<int, VisibilityCheckResultRecord>> $map */
            public function __construct(private readonly array $map)
            {
            }

            public function findByDocument(string $documentUuid, ?string $processKey = null): array
            {
                return $this->map[$documentUuid] ?? [];
            }
        };

        $container = static::getContainer();
        $container->set(DocumentListProvider::class, $list);
        $container->set(DocumentCheckResultProvider::class, $checkProvider);
        $container->set(VisibilityCheckResultProvider::class, $visibility);
    }

    private function row(string $uuid): DocumentListRow
    {
        return new DocumentListRow($uuid, 'DOC', 1, 1, new DateTimeImmutable('2026-06-15T09:00:00+00:00'));
    }

    private function record(string $status): VisibilityCheckResultRecord
    {
        return new VisibilityCheckResultRecord(
            1, 'doc', 1, 'ai-rechnungen', 'amagno', '01', 'after', 'route', 'profile',
            'external_today', 'amagno_magnet_documents', '1009', 'hidden', 'visible', $status, 'forbidden_visibility',
            new DateTimeImmutable('2026-06-15T10:00:00+00:00'), 1, true, 1, null
        );
    }
}
