<?php

namespace App\Tests\Service;

use App\Dto\MatchingContext;
use App\Dto\RenderedBlock;
use App\Dto\SyncOptions;
use App\Service\Amagno\ApiTokenProviderInterface;
use App\Service\Amagno\CredentialStoreInterface;
use App\Service\Amagno\DocumentGatewayInterface;
use App\Service\Amagno\DocumentTagWriter;
use App\Service\Checkpoint\CheckpointStore;
use App\Service\Export\ExporterRegistry;
use App\Service\FibuExportService;
use App\Service\Processing\DocumentMatrixBuilder;
use App\Service\Processing\MatchingProvider;
use App\Service\Processing\StampService;
use App\Service\Processing\TemplateRenderer;
use PHPUnit\Framework\TestCase;

class FibuExportServiceTest extends TestCase
{
    public function testSyncDeduplicatesFetchedDocumentsById(): void
    {
        $documentFetcher = $this->createMock(DocumentGatewayInterface::class);
        $matchingProvider = $this->createMock(MatchingProvider::class);
        $matrixBuilder = $this->createMock(DocumentMatrixBuilder::class);
        $templateRenderer = $this->createMock(TemplateRenderer::class);
        $exporterRegistry = $this->createMock(ExporterRegistry::class);
        $stampService = $this->createMock(StampService::class);
        $checkpointStore = $this->createMock(CheckpointStore::class);
        $tokenProvider = $this->createMock(ApiTokenProviderInterface::class);
        $tokenProvider
            ->expects($this->never())
            ->method('tokenForCredential');
        $credentialStore = $this->createMock(CredentialStoreInterface::class);
        $tagWriter = $this->createMock(DocumentTagWriter::class);

        $documents = [
            ['id' => 'doc-1', 'documentNumber' => '1000', 'changeDate' => '2026-03-13T08:00:00+00:00'],
            ['id' => 'doc-1', 'documentNumber' => '1000', 'changeDate' => '2026-03-13T08:00:00+00:00'],
            ['id' => 'doc-2', 'documentNumber' => '2000', 'changeDate' => '2026-03-13T09:00:00+00:00'],
        ];

        $documentFetcher
            ->expects($this->once())
            ->method('fetchDocuments')
            ->willReturn($documents);

        $matchingProvider
            ->expects($this->once())
            ->method('resolve')
            ->willReturn(new MatchingContext([], '{format_excel}', 'template.txt'));

        $matrixBuilder
            ->expects($this->once())
            ->method('build')
            ->with(
                $this->callback(static fn (array $docs): bool => count($docs) === 2 && $docs[0]['id'] === 'doc-1' && $docs[1]['id'] === 'doc-2')
            )
            ->willReturn([]);

        $templateRenderer
            ->expects($this->once())
            ->method('render')
            ->with(
                [],
                [],
                '{format_excel}',
                $this->isType('callable'),
                $this->callback(static fn (array $docs): bool => count($docs) === 2 && $docs[0]['id'] === 'doc-1' && $docs[1]['id'] === 'doc-2'),
                $this->isType('array')
            )
            ->willReturn([new RenderedBlock('A|B', 'export.txt', true)]);

        $exporterRegistry
            ->expects($this->once())
            ->method('export');

        $stampService
            ->expects($this->never())
            ->method('apply');

        $service = new FibuExportService(
            defaultBaseUri: 'https://example.test',
            defaultCredentialId: null,
            defaultApiToken: 'token',
            defaultApiUsername: null,
            defaultApiPassword: null,
            defaultApiAuthType: null,
            documentFetcher: $documentFetcher,
            matchingProvider: $matchingProvider,
            matrixBuilder: $matrixBuilder,
            templateRenderer: $templateRenderer,
            exporterRegistry: $exporterRegistry,
            stampService: $stampService,
            checkpointStore: $checkpointStore,
            tokenProvider: $tokenProvider,
            credentialStore: $credentialStore,
            tagWriter: $tagWriter
        );

        $result = $service->sync(new SyncOptions(
            magnetId: 'magnet',
            exportTarget: 'local',
            localFolder: sys_get_temp_dir(),
            batchSize: 50
        ));

        $this->assertSame(2, $result['document_count']);
        $this->assertCount(2, $result['documents']);
        $this->assertSame('doc-1', $result['documents'][0]['id']);
        $this->assertSame('doc-2', $result['documents'][1]['id']);
        $this->assertContains(
            'Dokumentenliste enthielt 1 Dublette(n); Verarbeitung auf 2 eindeutige Dokumente reduziert.',
            $result['debug']
        );
    }
}
