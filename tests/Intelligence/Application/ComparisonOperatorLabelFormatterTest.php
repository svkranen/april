<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\ComparisonOperatorLabelFormatter;
use PHPUnit\Framework\TestCase;

final class ComparisonOperatorLabelFormatterTest extends TestCase
{
    /**
     * @dataProvider operatorProvider
     */
    public function testTranslatesTechnicalOperatorsIntoReadableSymbols(string $operator, string $expected): void
    {
        self::assertSame($expected, (new ComparisonOperatorLabelFormatter())->toSymbol($operator));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function operatorProvider(): array
    {
        return [
            'gt' => ['gt', '>'],
            'gte' => ['gte', '>='],
            'lt' => ['lt', '<'],
            'lte' => ['lte', '<='],
            'eq' => ['eq', '='],
            'neq' => ['neq', '!='],
            'in' => ['in', 'in'],
            'not_in' => ['not_in', 'not in'],
            'contains' => ['contains', 'contains'],
            'starts_with' => ['starts_with', 'starts with'],
            'ends_with' => ['ends_with', 'ends with'],
        ];
    }

    public function testLeavesUnknownOperatorsUnchanged(): void
    {
        $formatter = new ComparisonOperatorLabelFormatter();

        self::assertSame('exists', $formatter->toSymbol('exists'));
        self::assertSame('something_new', $formatter->toSymbol('something_new'));
        self::assertSame('', $formatter->toSymbol(''));
    }
}
