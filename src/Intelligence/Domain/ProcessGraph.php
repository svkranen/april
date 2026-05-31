<?php

namespace App\Intelligence\Domain;

final readonly class ProcessGraph
{
    /**
     * @param array<string, ProcessGraphNode> $nodes
     * @param array<int, ProcessGraphEdge> $edges
     */
    public function __construct(
        public string $key,
        public string $version,
        public array $nodes,
        public array $edges
    ) {
    }
}
