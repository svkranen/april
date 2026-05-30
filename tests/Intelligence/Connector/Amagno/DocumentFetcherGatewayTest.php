<?php

namespace App\Tests\Intelligence\Connector\Amagno;

use App\Intelligence\Connector\Amagno\AmagnoDocumentGateway;
use App\Intelligence\Connector\Amagno\DocumentFetcherGateway;
use App\Service\Amagno\DocumentFetcher;
use PHPUnit\Framework\TestCase;

class DocumentFetcherGatewayTest extends TestCase
{
    public function testDelegatesDocumentTagsToDocumentFetcher(): void
    {
        $fetcher = $this->createMock(DocumentFetcher::class);
        $fetcher
            ->expects(self::once())
            ->method('fetchDocumentTags')
            ->with('doc-123', 'token', 'https://amagno.example')
            ->willReturn(['singleLineStrings' => []]);

        $gateway = new DocumentFetcherGateway($fetcher);

        self::assertInstanceOf(AmagnoDocumentGateway::class, $gateway);
        self::assertSame(
            ['singleLineStrings' => []],
            $gateway->fetchDocumentTags('doc-123', 'token', 'https://amagno.example')
        );
    }

    public function testDelegatesSelectionNodeToDocumentFetcher(): void
    {
        $fetcher = $this->createMock(DocumentFetcher::class);
        $fetcher
            ->expects(self::once())
            ->method('fetchSelectionNode')
            ->with('node-1', 'token', 'https://amagno.example')
            ->willReturn(['value' => 'KST-100']);

        $gateway = new DocumentFetcherGateway($fetcher);

        self::assertSame(
            ['value' => 'KST-100'],
            $gateway->fetchSelectionNode('node-1', 'token', 'https://amagno.example')
        );
    }
}
