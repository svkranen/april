<?php

namespace App\Intelligence\Application;

/**
 * Result of classifying a single {@see \App\Intelligence\Domain\ProcessDeviation}
 * for the process graph: it belongs either to a decision gateway node, to an
 * observed transition edge (from -> actual), or to neither (process-wide).
 *
 * Deliberately the only place that decides "where does this finding go"; pure data.
 */
final readonly class FindingAttribution
{
    public const TARGET_GATEWAY = 'gateway';
    public const TARGET_TRANSITION = 'transition';
    public const TARGET_PROCESS = 'process';

    private function __construct(
        public string $target,
        public ?string $nodeId = null,
        public ?string $from = null,
        public ?string $actual = null
    ) {
    }

    public static function gateway(string $nodeId): self
    {
        return new self(self::TARGET_GATEWAY, nodeId: $nodeId);
    }

    public static function transition(string $from, string $actual): self
    {
        return new self(self::TARGET_TRANSITION, from: $from, actual: $actual);
    }

    public static function process(): self
    {
        return new self(self::TARGET_PROCESS);
    }

    public function isProcess(): bool
    {
        return $this->target === self::TARGET_PROCESS;
    }
}
