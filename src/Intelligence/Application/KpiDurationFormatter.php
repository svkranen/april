<?php

namespace App\Intelligence\Application;

use InvalidArgumentException;

/** Formats an already calculated duration for human-readable presentation. */
final class KpiDurationFormatter
{
    public function format(float $seconds): string
    {
        if ($seconds < 0) {
            throw new InvalidArgumentException('A KPI duration cannot be negative.');
        }
        if ($seconds > 0 && $seconds < 1) {
            return '< 1 s';
        }

        $totalSeconds = (int) round($seconds);
        if ($totalSeconds === 0) {
            return '0 s';
        }

        $days = intdiv($totalSeconds, 86400);
        $hours = intdiv($totalSeconds % 86400, 3600);
        $minutes = intdiv($totalSeconds % 3600, 60);
        $remainder = $totalSeconds % 60;
        $parts = [];
        if ($days > 0) {
            $parts[] = $days.' d';
        }
        if ($hours > 0) {
            $parts[] = $hours.' h';
        }
        if ($minutes > 0) {
            $parts[] = $minutes.' min';
        }
        if ($remainder > 0) {
            $parts[] = $remainder.' s';
        }

        return implode(' ', $parts);
    }
}
