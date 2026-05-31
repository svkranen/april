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

    public function testParsesZuluTimeAsUtc(): void
    {
        $normalizer = new DateTimeNormalizer('Europe/Berlin');

        $dateTime = $normalizer->parseAmagnoValue('2026-05-31T05:08:00Z');

        self::assertSame('2026-05-31T05:08:00+00:00', $dateTime->format(DATE_ATOM));
    }
}
