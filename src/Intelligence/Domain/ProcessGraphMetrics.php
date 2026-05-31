<?php

namespace App\Intelligence\Domain;

final readonly class ProcessGraphMetrics
{
    /**
     * @param array<string, ProcessGraphNodeMetrics> $nodes
     * @param array<string, ProcessGraphEdgeMetrics> $edges
     */
    public function __construct(
        public array $nodes = [],
        public array $edges = []
    ) {
    }

    public static function edgeKey(string $from, string $to): string
    {
        return $from."\0".$to;
    }

    public function node(string $nodeId): ?ProcessGraphNodeMetrics
    {
        return $this->nodes[$nodeId] ?? null;
    }

    public function edge(string $from, string $to): ?ProcessGraphEdgeMetrics
    {
        return $this->edges[self::edgeKey($from, $to)] ?? null;
    }
}
