<?php

namespace App\Tests\Intelligence\Connector\Amagno;

use App\Intelligence\Connector\Amagno\AmagnoDocumentGateway;
use App\Intelligence\Connector\Amagno\AmagnoTagDefinitionResolver;
use PHPUnit\Framework\TestCase;

class AmagnoTagDefinitionResolverTest extends TestCase
{
    public function testResolvesNumberDefinitionByCaption(): void
    {
        $resolver = new AmagnoTagDefinitionResolver($this->gateway([
            'numberDefinitions' => [
                ['id' => 'amount-tag-id', 'caption' => 'Nettobetrag'],
            ],
        ]));

        $result = $resolver->resolveByCaption('Nettobetrag');

        self::assertSame('amount-tag-id', $result->tagDefinitionId);
        self::assertSame('numberDefinitions', $result->definitionType);
        self::assertNull($result->warning);
    }

    public function testResolvesSelectionDefinitionByCaption(): void
    {
        $resolver = new AmagnoTagDefinitionResolver($this->gateway([
            'selectionDefinitions' => [
                ['id' => 'direction-tag-id', 'caption' => 'Eingang/Ausgang'],
            ],
        ]));

        $result = $resolver->resolveByCaption('Eingang/Ausgang');

        self::assertSame('direction-tag-id', $result->tagDefinitionId);
        self::assertSame('selectionDefinitions', $result->definitionType);
        self::assertNull($result->warning);
    }

    public function testUnknownCaptionReturnsWarning(): void
    {
        $resolver = new AmagnoTagDefinitionResolver($this->gateway([
            'numberDefinitions' => [],
        ]));

        $result = $resolver->resolveByCaption('Nettobetrag');

        self::assertNull($result->tagDefinitionId);
        self::assertSame('Unknown Amagno tag_name "Nettobetrag".', $result->warning);
    }

    public function testAmbiguousCaptionReturnsWarning(): void
    {
        $resolver = new AmagnoTagDefinitionResolver($this->gateway([
            'numberDefinitions' => [
                ['id' => 'amount-1', 'caption' => 'Nettobetrag'],
            ],
            'counterDefinitions' => [
                ['id' => 'amount-2', 'caption' => 'Nettobetrag'],
            ],
        ]));

        $result = $resolver->resolveByCaption('Nettobetrag');

        self::assertNull($result->tagDefinitionId);
        self::assertSame('Ambiguous Amagno tag_name "Nettobetrag".', $result->warning);
    }

    public function testCachesLookupPerConnection(): void
    {
        $gateway = $this->createMock(AmagnoDocumentGateway::class);
        $gateway
            ->expects(self::once())
            ->method('fetchTagDefinitions')
            ->with(null, 'https://amagno.example/api/v2', 7)
            ->willReturn([
                'numberDefinitions' => [
                    ['id' => 'amount-tag-id', 'caption' => 'Nettobetrag'],
                ],
                'selectionDefinitions' => [
                    ['id' => 'direction-tag-id', 'caption' => 'Eingang/Ausgang'],
                ],
            ]);

        $resolver = new AmagnoTagDefinitionResolver($gateway);

        self::assertSame('amount-tag-id', $resolver->resolveByCaption('Nettobetrag', null, 'https://amagno.example/api/v2', 7)->tagDefinitionId);
        self::assertSame('direction-tag-id', $resolver->resolveByCaption('Eingang/Ausgang', null, 'https://amagno.example/api/v2', 7)->tagDefinitionId);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function gateway(array $payload): AmagnoDocumentGateway
    {
        $gateway = $this->createMock(AmagnoDocumentGateway::class);
        $gateway
            ->expects(self::once())
            ->method('fetchTagDefinitions')
            ->willReturn($payload);

        return $gateway;
    }
}
