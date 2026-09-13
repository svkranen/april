<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\KpiStatistics;
use PHPUnit\Framework\TestCase;

final class KpiStatisticsTest extends TestCase
{
    public function testEmptySampleHasNoDurationValues(): void
    {
        $stats = KpiStatistics::fromSeconds([]);
        self::assertSame(0, $stats->count);
        self::assertNull($stats->average);
        self::assertNull($stats->median);
        self::assertNull($stats->p90);
        self::assertNull($stats->maximum);
    }

    /** @dataProvider samples */
    public function testMedianAndNearestRankP90(array $sample, float $average, float $median, float $p90, float $max): void
    {
        $stats = KpiStatistics::fromSeconds($sample);
        self::assertSame($average, $stats->average);
        self::assertSame($median, $stats->median);
        self::assertSame($p90, $stats->p90);
        self::assertSame($max, $stats->maximum);
    }

    public static function samples(): iterable
    {
        yield 'one' => [[12.0], 12.0, 12.0, 12.0, 12.0];
        yield 'true zero' => [[0.0], 0.0, 0.0, 0.0, 0.0];
        yield 'odd' => [[9.0, 1.0, 5.0], 5.0, 5.0, 9.0, 9.0];
        yield 'even' => [[40.0, 10.0, 30.0, 20.0], 25.0, 25.0, 40.0, 40.0];
        yield 'rank nine of ten' => [[10.0, 9.0, 8.0, 7.0, 6.0, 5.0, 4.0, 3.0, 2.0, 1.0], 5.5, 5.5, 9.0, 10.0];
    }
}
