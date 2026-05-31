<?php

namespace App\Intelligence\Application;

final readonly class ObservedTransitionProjection
{
    public const EXPECTED_DIRECT = 'EXPECTED_DIRECT';
    public const EXPECTED_VIA_DECISION = 'EXPECTED_VIA_DECISION';
    public const EXPECTED_VIA_PARALLEL_GROUP = 'EXPECTED_VIA_PARALLEL_GROUP';
    public const EXPECTED_GROUP_INTERNAL = 'EXPECTED_GROUP_INTERNAL';
    public const EXPECTED_GROUP_COMPLETE = 'EXPECTED_GROUP_COMPLETE';
    public const UNEXPECTED = 'UNEXPECTED';

    /**
     * @param array<int, array{0: string, 1: string}> $projectedEdges
     */
    public function __construct(
        public string $classification,
        public array $projectedEdges = []
    ) {
    }

    public function isUnexpected(): bool
    {
        return $this->classification === self::UNEXPECTED;
    }
}
