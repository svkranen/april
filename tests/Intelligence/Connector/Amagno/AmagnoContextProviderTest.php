<?php

namespace App\Tests\Intelligence\Connector\Amagno;

use App\Intelligence\Domain\DocumentRef;
use App\Intelligence\Connector\Amagno\AmagnoContextProvider;
use App\Intelligence\Connector\Amagno\AmagnoTagValueResolver;
use App\Intelligence\Port\ContextProvider;
use App\Service\Amagno\DocumentFetcher;
use PHPUnit\Framework\TestCase;

class AmagnoContextProviderTest extends TestCase
{
    public function testLoadsOnlyRequestedFields(): void
    {
        $fetcher = $this->createMock(DocumentFetcher::class);
        $fetcher
            ->expects(self::once())
            ->method('fetchDocumentTags')
            ->with('doc-123', null, null)
            ->willReturn([
                'singleLineStrings' => [
                    ['tagDefinitionId' => 'doctype-tag', 'value' => 'Invoice'],
                    ['tagDefinitionId' => 'project-tag', 'value' => 'PRJ-1'],
                ],
            ]);
        $fetcher
            ->expects(self::never())
            ->method('fetchSelectionNode');

        $provider = new AmagnoContextProvider(
            $fetcher,
            new AmagnoTagValueResolver(),
            [
                'documentType' => 'doctype-tag',
                'projectNumber' => 'project-tag',
            ]
        );

        self::assertInstanceOf(ContextProvider::class, $provider);
        self::assertSame(
            [
                'documentVersion' => 2,
                'documentType' => 'Invoice',
            ],
            $provider->loadAttributes(new DocumentRef('amagno', 'doc-123', 'uuid-123', 2), [
                'documentVersion',
                'documentType',
            ])
        );
    }

    public function testReturnsMultiAttributesAsArraysAndResolvesSelectionsOnce(): void
    {
        $fetcher = $this->createMock(DocumentFetcher::class);
        $fetcher
            ->expects(self::once())
            ->method('fetchDocumentTags')
            ->with('doc-123', 'token', 'https://amagno.example')
            ->willReturn([
                'numbers' => [
                    ['tagDefinitionId' => 'amount-tag', 'value' => 100000000],
                    ['tagDefinitionId' => 'amount-tag', 'value' => 25000000],
                ],
                'selections' => [
                    ['tagDefinitionId' => 'cost-center-tag', 'selectedNodeIds' => ['node-1']],
                    ['tagDefinitionId' => 'cost-center-tag', 'selectedNodeIds' => ['node-1']],
                ],
            ]);
        $fetcher
            ->expects(self::once())
            ->method('fetchSelectionNode')
            ->with('node-1', 'token', 'https://amagno.example')
            ->willReturn(['value' => 'KST-100']);

        $provider = new AmagnoContextProvider(
            $fetcher,
            new AmagnoTagValueResolver(),
            [
                'amounts' => 'amount-tag',
                'costCenters' => 'cost-center-tag',
            ],
            'token',
            'https://amagno.example'
        );

        self::assertSame(
            [
                'amounts' => [10000.0, 2500.0],
                'costCenters' => ['KST-100', 'KST-100'],
                'approvals' => [],
            ],
            $provider->loadAttributes(new DocumentRef('amagno', 'doc-123', null, 1), [
                'amounts',
                'costCenters',
                'approvals',
            ])
        );
    }

    public function testDoesNotFetchTagsForDocumentOnlyFields(): void
    {
        $fetcher = $this->createMock(DocumentFetcher::class);
        $fetcher
            ->expects(self::never())
            ->method('fetchDocumentTags');

        $provider = new AmagnoContextProvider($fetcher, new AmagnoTagValueResolver());

        self::assertSame(
            [
                'documentId' => 'doc-123',
                'documentUuid' => 'uuid-123',
                'signatures' => [],
            ],
            $provider->loadAttributes(new DocumentRef('amagno', 'doc-123', 'uuid-123', 1), [
                'documentId',
                'documentUuid',
                'signatures',
            ])
        );
    }
}
