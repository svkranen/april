<?php

namespace App\Intelligence\Application;

/**
 * Translates technical decision-rule operators (gt, gte, lt, ...) into
 * human-readable comparison symbols for display in graphs and diagram labels.
 *
 * This is a presentation-only concern: it never touches rule evaluation,
 * template parsing or YAML logic. Unknown operators are returned unchanged.
 */
final class ComparisonOperatorLabelFormatter
{
    /**
     * @var array<string, string>
     */
    private const SYMBOLS = [
        'gt' => '>',
        'gte' => '>=',
        'lt' => '<',
        'lte' => '<=',
        'eq' => '=',
        'neq' => '!=',
        'in' => 'in',
        'not_in' => 'not in',
        'contains' => 'contains',
        'starts_with' => 'starts with',
        'ends_with' => 'ends with',
    ];

    public function toSymbol(string $operator): string
    {
        return self::SYMBOLS[$operator] ?? $operator;
    }
}
