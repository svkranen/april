<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\KpiPeriod;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class KpiPeriodTest extends TestCase
{
    public function testBothCalendarDatesAreIncludedButNextMidnightIsExcluded(): void
    {
        $period = KpiPeriod::fromDates('2026-02-01', '2026-02-02', 'UTC');
        self::assertTrue($period->contains(new DateTimeImmutable('2026-02-01T00:00:00Z')));
        self::assertTrue($period->contains(new DateTimeImmutable('2026-02-02T23:59:59.999999Z')));
        self::assertFalse($period->contains(new DateTimeImmutable('2026-02-03T00:00:00Z')));
        self::assertFalse($period->contains(new DateTimeImmutable('2026-01-31T23:59:59Z')));
        self::assertSame(['2026-02-01' => 0, '2026-02-02' => 0], $period->emptyBuckets());
    }

    public function testLongPeriodsUseCalendarMonthsIncludingPartialBoundaryMonths(): void
    {
        $period = KpiPeriod::fromDates('2026-01-31', '2026-04-03');
        self::assertSame('month', $period->grouping);
        self::assertSame(['2026-01' => 0, '2026-02' => 0, '2026-03' => 0, '2026-04' => 0], $period->emptyBuckets());
        self::assertSame('2026-01', $period->bucket(new DateTimeImmutable('2026-02-01T00:30:00+02:00')));
    }

    public function testSpringForwardUsesBerlinCalendarMidnightsConvertedToUtc(): void
    {
        $period = KpiPeriod::fromDates('2026-03-29', '2026-03-29');

        self::assertSame('2026-03-28T23:00:00+00:00', $period->from->format(DATE_ATOM));
        self::assertSame('2026-03-29T22:00:00+00:00', $period->until->format(DATE_ATOM));
        self::assertTrue($period->contains(new DateTimeImmutable('2026-03-29T21:59:59+00:00')));
        self::assertFalse($period->contains(new DateTimeImmutable('2026-03-29T22:00:00+00:00')));
    }

    public function testAutumnFoldUsesBerlinCalendarMidnightsAndDoesNotLoseTheRepeatedHour(): void
    {
        $period = KpiPeriod::fromDates('2026-10-25', '2026-10-25');

        self::assertSame('2026-10-24T22:00:00+00:00', $period->from->format(DATE_ATOM));
        self::assertSame('2026-10-25T23:00:00+00:00', $period->until->format(DATE_ATOM));
        self::assertTrue($period->contains(new DateTimeImmutable('2026-10-25T22:30:00+00:00')));
    }

    public function testEventsNearBerlinMidnightAreAssignedToTheirReportingDate(): void
    {
        $period = KpiPeriod::fromDates('2026-02-01', '2026-02-01');

        self::assertFalse($period->contains(new DateTimeImmutable('2026-01-31T22:59:59+00:00')));
        self::assertTrue($period->contains(new DateTimeImmutable('2026-01-31T23:00:00+00:00')));
        self::assertTrue($period->contains(new DateTimeImmutable('2026-02-01T22:59:59+00:00')));
        self::assertFalse($period->contains(new DateTimeImmutable('2026-02-01T23:00:00+00:00')));
    }

    /** @dataProvider invalidDates */
    public function testInvalidPeriodsAreRejected(string $from, string $to): void
    {
        $this->expectException(InvalidArgumentException::class);
        KpiPeriod::fromDates($from, $to);
    }

    public static function invalidDates(): iterable
    {
        yield ['2026-02-30', '2026-03-01'];
        yield ['2026-03-01', '2026-02-01'];
        yield ['2020-01-01', '2040-01-01'];
        yield ['tomorrow', '2026-02-01'];
    }
}
