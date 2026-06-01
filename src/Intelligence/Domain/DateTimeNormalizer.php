<?php

namespace App\Intelligence\Domain;

use DateTimeImmutable;
use DateTimeZone;

final readonly class DateTimeNormalizer
{
    private DateTimeZone $defaultTimezone;
    private DateTimeZone $utc;

    public function __construct(string $defaultTimezone = 'Europe/Berlin')
    {
        $this->defaultTimezone = new DateTimeZone($defaultTimezone);
        $this->utc = new DateTimeZone('UTC');
    }

    public function nowUtc(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->utc);
    }

    public function toUtc(DateTimeImmutable $dateTime): DateTimeImmutable
    {
        return $dateTime->setTimezone($this->utc);
    }

    public function parseAmagnoValue(string $value): DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return $this->nowUtc();
        }

        $value = $this->restoreFormUrlencodedOffset($value);
        $timezone = $this->hasExplicitTimezone($value) ? null : $this->defaultTimezone;

        return (new DateTimeImmutable($value, $timezone))->setTimezone($this->utc);
    }

    public function hasExplicitTimezone(string $value): bool
    {
        $value = trim($value);

        return preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', $value) === 1;
    }

    private function restoreFormUrlencodedOffset(string $value): string
    {
        return preg_replace_callback(
            '/(T\d{2}:\d{2}:\d{2})\s([+-]?)(\d{2}:\d{2})$/',
            static fn (array $matches): string => $matches[1].($matches[2] === '' ? '+' : $matches[2]).$matches[3],
            $value
        ) ?? $value;
    }
}
