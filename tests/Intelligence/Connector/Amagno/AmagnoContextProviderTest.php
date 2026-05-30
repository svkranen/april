<?php

namespace App\Tests\Intelligence\Connector\Amagno;

use App\Intelligence\Domain\DocumentRef;
use App\Intelligence\Connector\Amagno\AmagnoDocumentGateway;
use App\Intelligence\Connector\Amagno\AmagnoContextProvider;
use App\Intelligence\Connector\Amagno\AmagnoFieldMapping;
use App\Intelligence\Connector\Amagno\AmagnoTagDefinitionResolver;
use App\Intelligence\Connector\Amagno\AmagnoTagValueResolver;
use App\Intelligence\Port\ContextProvider;
use PHPUnit\Framework\TestCase;

class AmagnoContextProviderTest extends TestCase
{
    public function testLoadsOnlyRequestedFields(): void
    {
        $gateway = $this->createMock(AmagnoDocumentGateway::class);
        $gateway
            ->expects(self::once())
            ->method('fetchDocumentTags')
            ->with('uuid-123', null, null, null)
            ->willReturn([
                'singleLineStrings' => [
                    ['tagDefinitionId' => 'doctype-tag', 'value' => 'Invoice'],
                    ['tagDefinitionId' => 'project-tag', 'value' => 'PRJ-1'],
                ],
            ]);
        $gateway
            ->expects(self::never())
            ->method('fetchSelectionNode');

        $provider = new AmagnoContextProvider(
            $gateway,
            new AmagnoTagValueResolver(),
            new AmagnoTagDefinitionResolver($gateway),
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
        $gateway = $this->createMock(AmagnoDocumentGateway::class);
        $gateway
            ->expects(self::once())
            ->method('fetchDocumentTags')
            ->with('uuid-123', 'token', 'https://amagno.example', null)
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
        $gateway
            ->expects(self::once())
            ->method('fetchSelectionNode')
            ->with('node-1', 'token', 'https://amagno.example', null)
            ->willReturn(['value' => 'KST-100']);

        $provider = new AmagnoContextProvider(
            $gateway,
            new AmagnoTagValueResolver(),
            new AmagnoTagDefinitionResolver($gateway),
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
            $provider->loadAttributes(new DocumentRef('amagno', 'doc-123', 'uuid-123', 1), [
                'amounts',
                'costCenters',
                'approvals',
            ])
        );
    }

    public function testUsesDocumentUuidForTagEndpointInsteadOfNumericExternalId(): void
    {
        $gateway = $this->createMock(AmagnoDocumentGateway::class);
        $gateway
            ->expects(self::once())
            ->method('fetchDocumentTags')
            ->with('ce49b034-2dd6-40d6-31d4-08debe5b8599', null, null, null)
            ->willReturn([
                'numbers' => [
                    ['tagDefinitionId' => 'amount-tag-id', 'value' => 1234500],
                ],
            ]);

        $provider = new AmagnoContextProvider(
            $gateway,
            new AmagnoTagValueResolver(),
            new AmagnoTagDefinitionResolver($gateway),
            [
                'amount_net' => 'amount-tag-id',
            ]
        );

        self::assertSame(
            [
                'amount_net' => 123.45,
            ],
            $provider->loadAttributes(new DocumentRef('amagno', '41279310', 'ce49b034-2dd6-40d6-31d4-08debe5b8599', 1), [
                'amount_net',
            ])
        );
    }

    public function testMissingDocumentUuidDoesNotFetchTags(): void
    {
        $gateway = $this->createMock(AmagnoDocumentGateway::class);
        $gateway
            ->expects(self::never())
            ->method('fetchDocumentTags');

        $provider = new AmagnoContextProvider(
            $gateway,
            new AmagnoTagValueResolver(),
            new AmagnoTagDefinitionResolver($gateway),
            [
                'amount_net' => 'amount-tag-id',
            ]
        );

        self::assertSame(
            [],
            $provider->loadAttributes(new DocumentRef('amagno', '41279310', null, 1), [
                'amount_net',
            ])
        );
    }

    public function testDoesNotFetchTagsForDocumentOnlyFields(): void
    {
        $gateway = $this->createMock(AmagnoDocumentGateway::class);
        $gateway
            ->expects(self::never())
            ->method('fetchDocumentTags');

        $provider = new AmagnoContextProvider($gateway, new AmagnoTagValueResolver(), new AmagnoTagDefinitionResolver($gateway));

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

    public function testResolvesTagNameBeforeLoadingValues(): void
    {
        $gateway = $this->createMock(AmagnoDocumentGateway::class);
        $gateway
            ->expects(self::once())
            ->method('fetchTagDefinitions')
            ->willReturn([
                'numberDefinitions' => [
                    ['id' => 'amount-tag-id', 'caption' => 'Nettobetrag'],
                ],
                'selectionDefinitions' => [
                    ['id' => 'direction-tag-id', 'caption' => 'Eingang/Ausgang'],
                ],
            ]);
        $gateway
            ->expects(self::once())
            ->method('fetchDocumentTags')
            ->with('uuid-123', null, null, null)
            ->willReturn([
                'numbers' => [
                    ['tagDefinitionId' => 'amount-tag-id', 'value' => 830000],
                ],
                'selections' => [
                    ['tagDefinitionId' => 'direction-tag-id', 'selectedNodeIds' => ['node-direction']],
                ],
            ]);
        $gateway
            ->expects(self::once())
            ->method('fetchSelectionNode')
            ->with('node-direction', null, null, null)
            ->willReturn(['value' => 'RE - Eingang']);

        $provider = new AmagnoContextProvider(
            $gateway,
            new AmagnoTagValueResolver(),
            new AmagnoTagDefinitionResolver($gateway),
            [
                'amount_net' => new AmagnoFieldMapping('amount_net', tagName: 'Nettobetrag', valueType: 'number'),
                'invoice_direction' => new AmagnoFieldMapping('invoice_direction', tagName: 'Eingang/Ausgang'),
            ]
        );

        self::assertSame(
            [
                'amount_net' => 83.0,
                'invoice_direction' => 'RE - Eingang',
            ],
            $provider->loadAttributes(new DocumentRef('amagno', 'doc-123', 'uuid-123', 1), [
                'amount_net',
                'invoice_direction',
            ])
        );
        self::assertSame([], $provider->warnings());
    }

    public function testTagIdSkipsTagDefinitionLookup(): void
    {
        $gateway = $this->createMock(AmagnoDocumentGateway::class);
        $gateway
            ->expects(self::never())
            ->method('fetchTagDefinitions');
        $gateway
            ->expects(self::once())
            ->method('fetchDocumentTags')
            ->willReturn([
                'numbers' => [
                    ['tagDefinitionId' => 'amount-tag-id', 'value' => 830000],
                ],
            ]);

        $provider = new AmagnoContextProvider(
            $gateway,
            new AmagnoTagValueResolver(),
            new AmagnoTagDefinitionResolver($gateway),
            [
                'amount_net' => new AmagnoFieldMapping('amount_net', tagId: 'amount-tag-id', tagName: 'Nettobetrag'),
            ]
        );

        self::assertSame(
            ['amount_net' => 83.0],
            $provider->loadAttributes(new DocumentRef('amagno', 'doc-123', 'uuid-123', 1), ['amount_net'])
        );
    }

    public function testUnknownTagNameProducesWarning(): void
    {
        $gateway = $this->createMock(AmagnoDocumentGateway::class);
        $gateway
            ->expects(self::once())
            ->method('fetchTagDefinitions')
            ->willReturn(['numberDefinitions' => []]);
        $gateway
            ->expects(self::never())
            ->method('fetchDocumentTags');

        $provider = new AmagnoContextProvider(
            $gateway,
            new AmagnoTagValueResolver(),
            new AmagnoTagDefinitionResolver($gateway),
            [
                'amount_net' => new AmagnoFieldMapping('amount_net', tagName: 'Nettobetrag'),
            ]
        );

        self::assertSame([], $provider->loadAttributes(new DocumentRef('amagno', 'doc-123', 'uuid-123', 1), ['amount_net']));
        self::assertSame(['Unknown Amagno tag_name "Nettobetrag".'], $provider->warnings());
    }

    public function testAmbiguousTagNameProducesWarning(): void
    {
        $gateway = $this->createMock(AmagnoDocumentGateway::class);
        $gateway
            ->expects(self::once())
            ->method('fetchTagDefinitions')
            ->willReturn([
                'numberDefinitions' => [
                    ['id' => 'amount-1', 'caption' => 'Nettobetrag'],
                ],
                'counterDefinitions' => [
                    ['id' => 'amount-2', 'caption' => 'Nettobetrag'],
                ],
            ]);
        $gateway
            ->expects(self::never())
            ->method('fetchDocumentTags');

        $provider = new AmagnoContextProvider(
            $gateway,
            new AmagnoTagValueResolver(),
            new AmagnoTagDefinitionResolver($gateway),
            [
                'amount_net' => new AmagnoFieldMapping('amount_net', tagName: 'Nettobetrag'),
            ]
        );

        self::assertSame([], $provider->loadAttributes(new DocumentRef('amagno', 'doc-123', 'uuid-123', 1), ['amount_net']));
        self::assertSame(['Ambiguous Amagno tag_name "Nettobetrag".'], $provider->warnings());
    }
}
