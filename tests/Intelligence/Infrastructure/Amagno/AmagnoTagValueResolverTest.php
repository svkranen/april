<?php

namespace App\Tests\Intelligence\Infrastructure\Amagno;

use App\Intelligence\Infrastructure\Amagno\AmagnoTagValueResolver;
use PHPUnit\Framework\TestCase;

class AmagnoTagValueResolverTest extends TestCase
{
    public function testResolvesScalarSelectionAndMultiValues(): void
    {
        $resolver = new AmagnoTagValueResolver();
        $tags = [
            'singleLineStrings' => [
                ['tagDefinitionId' => 'title-tag', 'value' => ' Rechnung '],
            ],
            'numbers' => [
                ['tagDefinitionId' => 'amount-tag', 'value' => 120000000],
            ],
            'selections' => [
                ['tagDefinitionId' => 'cost-center-tag', 'selectedNodeIds' => ['node-1']],
            ],
            'dates' => [
                ['tagDefinitionId' => 'period-tag', 'value' => '2026-05-29T00:00:00+00:00'],
                ['tagDefinitionId' => 'period-tag', 'value' => '2026-05-30T00:00:00+00:00'],
            ],
        ];

        $selectionResolver = static fn (string $nodeId): array => ['value' => 'KST-100'];

        self::assertSame(['Rechnung'], $resolver->resolveValues($tags, 'title-tag', $selectionResolver));
        self::assertSame([12000.0], $resolver->resolveValues($tags, 'amount-tag', $selectionResolver));
        self::assertSame(['KST-100'], $resolver->resolveValues($tags, 'cost-center-tag', $selectionResolver));
        self::assertSame(
            ['2026-05-29T00:00:00+00:00', '2026-05-30T00:00:00+00:00'],
            $resolver->resolveValues($tags, 'period-tag', $selectionResolver)
        );
    }
}
