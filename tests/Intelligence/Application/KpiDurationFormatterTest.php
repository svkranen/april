<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\KpiDurationFormatter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class KpiDurationFormatterTest extends TestCase
{
    /** @dataProvider durationProvider */
    public function testFormatsDurationsWithoutChangingTheNumericValue(float $seconds, string $expected): void
    {
        self::assertSame($expected, (new KpiDurationFormatter())->format($seconds));
    }

    public function testRejectsNegativeDurations(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new KpiDurationFormatter())->format(-1);
    }

    /** @return iterable<string, array{float, string}> */
    public static function durationProvider(): iterable
    {
        yield 'seconds' => [45.0, '45 s'];
        yield 'minutes' => [300.0, '5 min'];
        yield 'hours and minutes' => [4800.0, '1 h 20 min'];
        yield 'days and hours' => [450000.0, '5 d 5 h'];
        yield 'combined units' => [450125.0, '5 d 5 h 2 min 5 s'];
        yield 'zero' => [0.0, '0 s'];
        yield 'subsecond' => [0.5, '< 1 s'];
    }
}
