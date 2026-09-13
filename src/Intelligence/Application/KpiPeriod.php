<?php

namespace App\Intelligence\Application;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class KpiPeriod
{
    private function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $until,
        public string $grouping,
        public DateTimeZone $reportingTimezone
    )
    {
    }

    public static function fromDates(string $from, string $to, string $reportingTimezone = 'Europe/Berlin'): self
    {
        try {
            $timezone = new DateTimeZone($reportingTimezone);
        } catch (\Exception $exception) {
            throw new InvalidArgumentException('Invalid reporting timezone.', 0, $exception);
        }
        $startLocal = self::date($from, $timezone);
        $endLocal = self::date($to, $timezone)->modify('+1 day');
        $start = $startLocal->setTimezone(new DateTimeZone('UTC'));
        $end = $endLocal->setTimezone(new DateTimeZone('UTC'));
        $days = (int) $startLocal->diff($endLocal)->format('%r%a');
        if ($days < 1 || $days > 3660) {
            throw new InvalidArgumentException('Invalid KPI period.');
        }

        return new self($start, $end, $days <= 62 ? 'day' : 'month', $timezone);
    }

    private static function date(string $date, DateTimeZone $timezone): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Invalid KPI date.');
        }

        return $parsed;
    }

    public function contains(?DateTimeImmutable $date): bool
    {
        return $date !== null && $date >= $this->from && $date < $this->until;
    }

    public function toDate(): string
    {
        return $this->until->setTimezone($this->reportingTimezone)->modify('-1 day')->format('Y-m-d');
    }

    public function fromDate(): string
    {
        return $this->from->setTimezone($this->reportingTimezone)->format('Y-m-d');
    }

    public function bucket(DateTimeImmutable $date): string
    {
        return $date->setTimezone($this->reportingTimezone)->format($this->grouping === 'day' ? 'Y-m-d' : 'Y-m');
    }

    /** @return array<string, int> */
    public function emptyBuckets(): array
    {
        $buckets = [];
        $cursor = $this->from->setTimezone($this->reportingTimezone);
        if ($this->grouping !== 'day') {
            $cursor = $cursor->modify('first day of this month')->setTime(0, 0);
        }
        $localUntil = $this->until->setTimezone($this->reportingTimezone);
        while ($cursor < $localUntil) {
            $buckets[$this->bucket($cursor)] = 0;
            $cursor = $cursor->modify($this->grouping === 'day' ? '+1 day' : '+1 month');
        }

        return $buckets;
    }
}
