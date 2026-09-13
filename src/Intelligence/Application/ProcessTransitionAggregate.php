<?php

namespace App\Intelligence\Application;

final readonly class ProcessTransitionAggregate
{
    public function __construct(
        public string $fromStep,
        public string $toStep,
        public int $itemCount,
        public int $visitCount,
        /** @var array<string, true> */
        public array $runKeys = []
    ) {
    }
}
