<?php

declare(strict_types=1);

namespace App\Intelligence\Application;

use JsonSerializable;

/** JSON-compatible, versioned boundary between APRIL and a graph renderer. */
final readonly class TemplateGraphModel implements JsonSerializable
{
    /**
     * @param array<int, array<string, mixed>> $nodes
     * @param array<int, array<string, mixed>> $edges
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $overlay
     */
    public function __construct(
        public string $graphType,
        public array $nodes,
        public array $edges,
        public array $metadata = [],
        public array $overlay = [],
        public string $schemaVersion = '1.0',
        public string $direction = 'left-to-right'
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $model = [
            'schemaVersion' => $this->schemaVersion,
            'graphType' => $this->graphType,
            'direction' => $this->direction,
            'nodes' => $this->nodes,
            'edges' => $this->edges,
            'metadata' => $this->metadata,
        ];
        if ($this->overlay !== []) {
            $model['overlay'] = $this->overlay;
        }

        return $model;
    }

    public function toJson(): string
    {
        return json_encode(
            $this,
            JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        );
    }
}
