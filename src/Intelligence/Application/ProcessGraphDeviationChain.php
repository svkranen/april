<?php

namespace App\Intelligence\Application;

final readonly class ProcessGraphDeviationChain
{
    /** @param list<array{from: string, to: string}> $transitions */
    public function __construct(
        public string $runKey,
        public array $transitions,
        public bool $returnedToExpectedPath,
        public bool $open,
        public bool $completedOutsideExpectedPath
    ) {
    }
}
