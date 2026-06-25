<?php

namespace App\Intelligence\Domain;

/**
 * Structured, machine-readable companion to a Soll/Ist deviation message.
 *
 * The human-readable message stays the single source of truth for display and
 * is kept byte-identical to the historical text; this value object only carries
 * the typed fields that were already known at the point the deviation was
 * detected (e.g. the step a wrong transition started from, the decision point a
 * rule violation belongs to). It exists so downstream consumers (the process
 * graph) can attribute a deviation to an edge or a decision gateway WITHOUT
 * parsing free text. Deviations that cannot be classified safely carry
 * {@see self::TYPE_OTHER} and are treated as non-attributable (process-wide).
 *
 * Pure data, never persisted.
 */
final readonly class ProcessDeviation
{
    /** Wrong observed transition: {@see $from} -> {@see $actual}, expected {@see $expected}. */
    public const TYPE_TRANSITION_VIOLATION = 'transition_violation';

    /** Decision rule problem at decision point {@see $decisionKey} (after {@see $after}). */
    public const TYPE_DECISION_RULE_VIOLATION = 'decision_rule_violation';

    /** Anything not safely attributable to an edge or gateway. */
    public const TYPE_OTHER = 'other';

    /**
     * @param array<int, string> $expected allowed/expected target step keys (may be empty)
     */
    public function __construct(
        public string $type,
        public string $message,
        public ?string $from = null,
        public ?string $actual = null,
        public array $expected = [],
        public ?string $decisionKey = null,
        public ?string $after = null
    ) {
    }

    /**
     * @param array<int, string> $expected
     */
    public static function transitionViolation(string $message, string $from, ?string $actual, array $expected): self
    {
        return new self(self::TYPE_TRANSITION_VIOLATION, $message, from: $from, actual: $actual, expected: $expected);
    }

    public static function decisionRuleViolation(string $message, string $decisionKey, ?string $after = null, ?string $actual = null): self
    {
        return new self(self::TYPE_DECISION_RULE_VIOLATION, $message, actual: $actual, decisionKey: $decisionKey, after: $after);
    }
}
