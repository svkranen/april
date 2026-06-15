<?php

namespace App\Intelligence\Application;

/**
 * Allowed severity filter values for the per-template document list, plus their
 * human-readable labels and normalisation of (untrusted) query values.
 */
final class FindingSeverityFilter
{
    public const ALL = 'all';
    public const CRITICAL = 'critical';
    public const DEVIATION = 'deviation';
    public const WARNING = 'warning';
    public const TECHNICAL = 'technical';
    public const OK = 'ok';
    public const NOT_CALCULATED = 'not_calculated';

    /**
     * Ordered value => label map (also drives the filter bar order).
     *
     * @var array<string, string>
     */
    public const OPTIONS = [
        self::ALL => 'Alle',
        self::CRITICAL => 'Kritisch',
        self::DEVIATION => 'Abweichung',
        self::WARNING => 'Warnung',
        self::TECHNICAL => 'Technisch',
        self::OK => 'OK',
        self::NOT_CALCULATED => 'Nicht berechnet',
    ];

    public static function normalize(?string $value): string
    {
        return $value !== null && array_key_exists($value, self::OPTIONS) ? $value : self::ALL;
    }

    public static function label(string $value): string
    {
        return self::OPTIONS[$value] ?? self::OPTIONS[self::ALL];
    }
}
