<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\DocumentCheckResultProvider;
use App\Intelligence\Application\DocumentCheckResultView;
use App\Intelligence\Application\DocumentListFindingsProvider;
use App\Intelligence\Application\ProcessTemplateCheckResult;
use App\Intelligence\Application\VisibilityCheckResultProvider;
use App\Intelligence\Application\VisibilityCheckResultRecord;
use App\Intelligence\Domain\ProcessTemplate;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DocumentListFindingsProviderTest extends TestCase
{
    public function testComputesFindingsPerDocument(): void
    {
        $provider = new DocumentListFindingsProvider(
            $this->checkProvider(DocumentCheckResultView::fromResult(new ProcessTemplateCheckResult([], [], []))),
            $this->visibilityProvider([$this->record('violation')])
        );

        $result = $provider->forDocuments($this->template(), ['doc-1', 'doc-2'], 50);

        self::assertCount(2, $result);
        self::assertSame('critical', $result['doc-1']->severity);
        self::assertSame('Kritisch', $result['doc-1']->label);
        self::assertSame(1, $result['doc-1']->countsByCategory['access']);
    }

    public function testProcessDeviationLeadsToDeviationRow(): void
    {
        $check = DocumentCheckResultView::fromResult(new ProcessTemplateCheckResult(['01', '02'], ['01'], ['fehlt 02'], [], [], null, []));
        $provider = new DocumentListFindingsProvider($this->checkProvider($check), $this->visibilityProvider([]));

        $result = $provider->forDocuments($this->template(), ['doc-1'], 50);

        self::assertSame('deviation', $result['doc-1']->severity);
        self::assertSame('Abweichung', $result['doc-1']->label);
    }

    public function testRespectsLimit(): void
    {
        $provider = new DocumentListFindingsProvider(
            $this->checkProvider(DocumentCheckResultView::fromResult(new ProcessTemplateCheckResult([], [], []))),
            $this->visibilityProvider([])
        );

        $result = $provider->forDocuments($this->template(), ['a', 'b', 'c', 'd'], 2);

        self::assertCount(2, $result);
        self::assertArrayHasKey('a', $result);
        self::assertArrayHasKey('b', $result);
        self::assertArrayNotHasKey('c', $result);
    }

    public function testSingleDocumentErrorDegradesToTechnicalRow(): void
    {
        $visibility = new class implements VisibilityCheckResultProvider {
            public function findByDocument(string $documentUuid, ?string $processKey = null): array
            {
                throw new RuntimeException('db down');
            }
        };
        $provider = new DocumentListFindingsProvider(
            $this->checkProvider(DocumentCheckResultView::fromResult(new ProcessTemplateCheckResult([], [], []))),
            $visibility
        );

        $result = $provider->forDocuments($this->template(), ['doc-1'], 50);

        self::assertSame('technical', $result['doc-1']->severity);
        self::assertSame('Technisch', $result['doc-1']->label);
        self::assertSame('db down', $result['doc-1']->error);
    }

    private function template(): ProcessTemplate
    {
        return new ProcessTemplate(key: 'ai-rechnungen', version: '1.1');
    }

    private function checkProvider(DocumentCheckResultView $view): DocumentCheckResultProvider
    {
        return new class($view) implements DocumentCheckResultProvider {
            public function __construct(private readonly DocumentCheckResultView $view)
            {
            }

            public function forDocument(ProcessTemplate $template, string $documentUuid): DocumentCheckResultView
            {
                return $this->view;
            }
        };
    }

    /**
     * @param array<int, VisibilityCheckResultRecord> $records
     */
    private function visibilityProvider(array $records): VisibilityCheckResultProvider
    {
        return new class($records) implements VisibilityCheckResultProvider {
            /** @param array<int, VisibilityCheckResultRecord> $records */
            public function __construct(private readonly array $records)
            {
            }

            public function findByDocument(string $documentUuid, ?string $processKey = null): array
            {
                return $this->records;
            }
        };
    }

    private function record(string $status): VisibilityCheckResultRecord
    {
        return new VisibilityCheckResultRecord(
            1, 'doc-1', 1, 'ai-rechnungen', 'amagno', '01', 'after', 'route', 'profile',
            'external_today', 'amagno_magnet_documents', '1009', 'hidden', 'visible', $status, 'forbidden_visibility',
            new DateTimeImmutable('2026-06-15T10:00:00+00:00'), 1, true, 1, null
        );
    }
}
