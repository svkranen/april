<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Domain\DateTimeNormalizer;
use PHPUnit\Framework\TestCase;

final class DateTimeNormalizerTest extends TestCase
{
    public function testParsesAmagnoLocalTimeWithoutOffsetAsConfiguredTimezoneAndConvertsToUtc(): void
    {
        $normalizer = new DateTimeNormalizer('Europe/Berlin');

        $dateTime = $normalizer->parseAmagnoValue('2026-05-31 07:08:00');

        self::assertSame('2026-05-31T05:08:00+00:00', $dateTime->format(DATE_ATOM));
    }

    public function testParsesExplicitOffsetAndConvertsToUtc(): void
    {
        $normalizer = new DateTimeNormalizer('Europe/Berlin');

        $dateTime = $normalizer->parseAmagnoValue('2026-05-31T07:08:00+02:00');

        self::assertSame('2026-05-31T05:08:00+00:00', $dateTime->format(DATE_ATOM));
    }

    /**
     * @dataProvider explicitTimezoneValues
     */
    public function testParsesExplicitTimezoneValuesAndConvertsToUtc(string $value, string $expected): void
    {
        $normalizer = new DateTimeNormalizer('Europe/Berlin');

        $dateTime = $normalizer->parseAmagnoValue($value);

        self::assertSame($expected, $dateTime->format(DATE_ATOM));
    }

    public function testParsesZuluTimeAsUtc(): void
    {
        $normalizer = new DateTimeNormalizer('Europe/Berlin');

        $dateTime = $normalizer->parseAmagnoValue('2026-05-31T05:08:00Z');

        self::assertSame('2026-05-31T05:08:00+00:00', $dateTime->format(DATE_ATOM));
    }

    public static function explicitTimezoneValues(): iterable
    {
        yield 'plus offset' => ['2026-05-31T18:45:00+00:00', '2026-05-31T18:45:00+00:00'];
        yield 'encoded plus offset after request decoding' => ['2026-05-31T18:45:00+00:00', '2026-05-31T18:45:00+00:00'];
        yield 'form-urlencoded plus decoded as space for UTC' => ['2026-05-31T18:45:00 00:00', '2026-05-31T18:45:00+00:00'];
        yield 'form-urlencoded plus decoded as space for positive offset' => ['2026-05-31T18:45:00 02:00', '2026-05-31T16:45:00+00:00'];
        yield 'zulu time' => ['2026-05-31T18:45:00Z', '2026-05-31T18:45:00+00:00'];
    }
}
