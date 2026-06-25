<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\EnrichedProcessGraph;
use App\Intelligence\Domain\ProcessGraph;
use App\Intelligence\Domain\ProcessGraphEdge;
use App\Intelligence\Domain\ProcessGraphEdgeMetrics;
use App\Intelligence\Domain\ProcessGraphMetrics;
use App\Intelligence\Domain\ProcessGraphNode;
use App\Intelligence\Domain\ProcessGraphNodeMetrics;

final class MermaidProcessGraphRenderer
{
    private const DWELL_RELATIVE_NOTE = 'Node color = relative dwell time. Dwell colors use a relative yellow-to-red percentile scale. Red means longest dwell time in the current dataset, not automatically critical.';
    private const FLOW_RELATIVE_NOTE = 'Node color = relative document volume. Edge width = relative edge volume. Flow colors use a relative yellow-to-red percentile scale. Red means highest document volume in the current dataset, not critical.';
    private const COMBINED_NOTE = 'Node color = dwell time, edge width = volume, red dashed edges = deviations. Red means relatively high value in the current dataset, not automatically critical.';
    private const RELATIVE_PALETTE = [
        '#fefce8',
        '#fef9c3',
        '#fef08a',
        '#fde68a',
        '#fed7aa',
        '#fdba74',
        '#fecaca',
        '#fee2e2',
    ];

    /**
     * @return array<int, array{class: string, max_seconds: float|null, fill: string, stroke: string, stroke_width: int|null}>
     */
    public static function dwellBuckets(): array
    {
        return [
            ['class' => 'dwell-very-low', 'max_seconds' => 300.0, 'fill' => '#ecfdf5', 'stroke' => '#10b981', 'stroke_width' => null],
            ['class' => 'dwell-low', 'max_seconds' => 900.0, 'fill' => '#d1fae5', 'stroke' => '#10b981', 'stroke_width' => null],
            ['class' => 'dwell-moderate', 'max_seconds' => 1800.0, 'fill' => '#fef9c3', 'stroke' => '#ca8a04', 'stroke_width' => null],
            ['class' => 'dwell-warm', 'max_seconds' => 3600.0, 'fill' => '#fde68a', 'stroke' => '#d97706', 'stroke_width' => null],
            ['class' => 'dwell-medium', 'max_seconds' => 7200.0, 'fill' => '#fed7aa', 'stroke' => '#ea580c', 'stroke_width' => null],
            ['class' => 'dwell-high', 'max_seconds' => 14400.0, 'fill' => '#fdba74', 'stroke' => '#ea580c', 'stroke_width' => null],
            ['class' => 'dwell-very-high', 'max_seconds' => 28800.0, 'fill' => '#fecaca', 'stroke' => '#dc2626', 'stroke_width' => null],
            ['class' => 'dwell-critical', 'max_seconds' => null, 'fill' => '#fee2e2', 'stroke' => '#b91c1c', 'stroke_width' => 3],
        ];
    }

    /**
     * @return list<array{class: string, bucket: int, fill: string}>
     */
    public static function relativeDwellBuckets(int $bucketCount = 8): array
    {
        return self::relativeBuckets('dwell-scale-', $bucketCount);
    }

    /**
     * @return list<array{class: string, bucket: int, fill: string}>
     */
    public static function relativeFlowBuckets(int $bucketCount = 8): array
    {
        return self::relativeBuckets('flow-scale-', $bucketCount);
    }

    /**
     * @return list<array{class: string, bucket: int, fill: string}>
     */
    private static function relativeBuckets(string $classPrefix, int $bucketCount): array
    {
        $bucketCount = max(1, $bucketCount);
        $buckets = [];
        for ($bucket = 0; $bucket < $bucketCount; ++$bucket) {
            $paletteIndex = $bucketCount === 1
                ? 0
                : (int) round(($bucket / ($bucketCount - 1)) * (count(self::RELATIVE_PALETTE) - 1));
            $buckets[] = [
                'class' => $classPrefix.$bucket,
                'bucket' => $bucket,
                'fill' => self::RELATIVE_PALETTE[$paletteIndex],
            ];
        }

        return $buckets;
    }

    public static function dwellRelativeNote(): string
    {
        return self::DWELL_RELATIVE_NOTE;
    }

    public static function flowRelativeNote(): string
    {
        return self::FLOW_RELATIVE_NOTE;
    }

    public static function combinedNote(): string
    {
        return self::COMBINED_NOTE;
    }

    public function render(ProcessGraph|EnrichedProcessGraph $graph, bool|MermaidProcessGraphRenderOptions $options = false): string
    {
        $options = is_bool($options) ? new MermaidProcessGraphRenderOptions($options) : $options;
        $metrics = $graph instanceof EnrichedProcessGraph ? $graph->metrics : new ProcessGraphMetrics();
        $graph = $graph instanceof EnrichedProcessGraph ? $graph->graph : $graph;
        $lines = ['flowchart TD'];
        if ($options->view === MermaidProcessGraphRenderOptions::VIEW_COMBINED) {
            $lines[] = '  %% '.self::COMBINED_NOTE;
        } elseif ($options->showsDwellMetrics() && $options->dwellScale === MermaidProcessGraphRenderOptions::DWELL_SCALE_RELATIVE_PERCENTILE) {
            $lines[] = '  %% '.self::DWELL_RELATIVE_NOTE;
            $lines[] = '  %% Neutral light yellow means no reliable dwell measurement is available or the node is virtual process structure.';
        } elseif ($options->showsNodeFlowMetrics()) {
            $lines[] = '  %% '.self::FLOW_RELATIVE_NOTE;
        }
        $nodeIds = array_keys($graph->nodes);
        sort($nodeIds);
        $dwellClassesByNode = $options->showsDwellMetrics() ? $this->dwellBucketClassesByNode($metrics, $options) : [];
        $flowClassesByNode = $options->showsNodeFlowMetrics() ? $this->flowBucketClassesByNode($metrics, $options) : [];

        foreach ($nodeIds as $nodeId) {
            $node = $graph->nodes[$nodeId];
            $classes = [];
            if ($node->required) {
                $classes[] = 'required';
            }
            if ($node->type === ProcessGraphNode::TYPE_PARALLEL_GROUP) {
                $classes[] = 'constraint';
            }
            if ($node->type === ProcessGraphNode::TYPE_PARALLEL_START || $node->type === ProcessGraphNode::TYPE_PARALLEL_JOIN) {
                $classes[] = 'constraint';
            }
            $nodeMetrics = $metrics->node($node->id);
            if (isset($flowClassesByNode[$node->id])) {
                $classes[] = $flowClassesByNode[$node->id];
            } elseif ($options->showsNodeFlowMetrics()) {
                $classes[] = 'no-dwell';
            } elseif ($options->showsDwellMetrics() && $node->type !== ProcessGraphNode::TYPE_TASK) {
                $classes[] = 'no-dwell';
            } elseif (isset($dwellClassesByNode[$node->id])) {
                $classes[] = $dwellClassesByNode[$node->id];
            } elseif ($options->showsDwellMetrics()) {
                $classes[] = 'no-dwell';
            }
            if ($options->showsDeviationMetrics() && $nodeMetrics !== null && $nodeMetrics->deviationCount > 0) {
                $classes[] = 'node-deviation';
            }

            $lines[] = '  '.$this->mermaidId($node->id).$this->nodeShape($node, $nodeMetrics, $options).($classes === [] ? '' : ':::'.implode(',', $classes));
        }

        $linkStyles = [];
        $renderedEdgeKeys = [];
        $edgeIndex = 0;
        foreach ($graph->edges as $edge) {
            if ($edge->style === ProcessGraphEdge::STYLE_IMPLICIT && !$options->showDefaultOrder) {
                continue;
            }

            $edgeMetrics = $metrics->edge($edge->from, $edge->to);
            $lines[] = '  '.$this->edge($edge, $options, $edgeMetrics);
            $linkStyle = $this->linkStyle($edgeMetrics, $options);
            if ($linkStyle !== null) {
                $linkStyles[] = sprintf('  linkStyle %d %s;', $edgeIndex, $linkStyle);
            }

            $renderedEdgeKeys[ProcessGraphMetrics::edgeKey($edge->from, $edge->to)] = true;
            ++$edgeIndex;
        }

        foreach ($this->observedOnlyEdges($metrics, $renderedEdgeKeys) as $edgeMetrics) {
            $edge = new ProcessGraphEdge($edgeMetrics->from, $edgeMetrics->to, null, null, ProcessGraphEdge::STYLE_FLOW);
            $lines[] = '  '.$this->edge($edge, $options, $edgeMetrics);
            $linkStyle = $this->linkStyle($edgeMetrics, $options);
            if ($linkStyle !== null) {
                $linkStyles[] = sprintf('  linkStyle %d %s;', $edgeIndex, $linkStyle);
            }

            ++$edgeIndex;
        }

        $lines[] = '  classDef required stroke:#0f766e,stroke-width:3px;';
        $lines[] = '  classDef constraint stroke:#7c3aed,stroke-dasharray: 5 5;';
        $lines[] = '  classDef implicit stroke:#64748b,stroke-dasharray: 4 4;';
        foreach (self::relativeDwellBuckets($options->dwellBuckets) as $bucket) {
            $lines[] = sprintf('  classDef %s fill:%s;', $bucket['class'], $bucket['fill']);
        }
        foreach (self::relativeFlowBuckets($options->flowBuckets) as $bucket) {
            $lines[] = sprintf('  classDef %s fill:%s;', $bucket['class'], $bucket['fill']);
        }
        foreach (self::dwellBuckets() as $bucket) {
            $definition = sprintf('  classDef %s fill:%s,stroke:%s', $bucket['class'], $bucket['fill'], $bucket['stroke']);
            if ($bucket['stroke_width'] !== null) {
                $definition .= sprintf(',stroke-width:%dpx', $bucket['stroke_width']);
            }
            $lines[] = $definition.';';
        }
        $lines[] = '  classDef no-dwell fill:#fefce8,stroke:#a8a29e;';
        $lines[] = '  classDef node-deviation stroke:#dc2626,stroke-width:3px;';

        foreach ($linkStyles as $linkStyle) {
            $lines[] = $linkStyle;
        }

        return implode("\n", $lines)."\n";
    }

    private function nodeShape(ProcessGraphNode $node, ?ProcessGraphNodeMetrics $metrics, MermaidProcessGraphRenderOptions $options): string
    {
        $label = $this->label($this->nodeLabel($node, $metrics, $options));

        return match ($node->type) {
            ProcessGraphNode::TYPE_START, ProcessGraphNode::TYPE_END => sprintf('((%s))', $label),
            ProcessGraphNode::TYPE_PARALLEL_START, ProcessGraphNode::TYPE_PARALLEL_JOIN => sprintf('{{%s}}', $label),
            ProcessGraphNode::TYPE_EXCLUSIVE_GATEWAY => sprintf('{%s}', $label),
            ProcessGraphNode::TYPE_PARALLEL_GROUP => sprintf('[[%s]]', $label),
            default => sprintf('["%s"]', $label),
        };
    }

    private function nodeLabel(ProcessGraphNode $node, ?ProcessGraphNodeMetrics $metrics, MermaidProcessGraphRenderOptions $options): string
    {
        if (!$options->showNodeMetrics || !$options->showsNodeFlowMetrics() || $metrics === null || $metrics->flowCount === null) {
            return $node->label;
        }

        return $node->label."\ndocs: ".$metrics->flowCount;
    }

    private function edge(ProcessGraphEdge $edge, MermaidProcessGraphRenderOptions $options, ?ProcessGraphEdgeMetrics $metrics = null): string
    {
        $from = $this->mermaidId($edge->from);
        $to = $this->mermaidId($edge->to);
        $label = $this->edgeMetricLabel($edge->label ?? $edge->condition, $metrics, $options);
        $deviationEdge = $options->showsDeviationMetrics()
            && $metrics !== null
            && ($metrics->isObservedOnly || $metrics->deviationCount > 0);

        if ($edge->style === ProcessGraphEdge::STYLE_CONSTRAINT || $edge->style === ProcessGraphEdge::STYLE_IMPLICIT || $deviationEdge) {
            if ($label === null || trim($label) === '') {
                return sprintf('%s -.-> %s', $from, $to);
            }

            return sprintf('%s -.->|%s| %s', $from, $this->edgeLabel($label, $options), $to);
        }

        if ($label === null || trim($label) === '') {
            return sprintf('%s --> %s', $from, $to);
        }

        return sprintf('%s -->|%s| %s', $from, $this->edgeLabel($label, $options), $to);
    }

    private function edgeMetricLabel(?string $label, ?ProcessGraphEdgeMetrics $metrics, MermaidProcessGraphRenderOptions $options): ?string
    {
        if (!$options->showsFlowMetrics() || $metrics === null || $metrics->observedCount <= 0) {
            return $label;
        }

        $countLabel = 'count '.$metrics->observedCount;
        if ($label === null || trim($label) === '') {
            return $countLabel;
        }

        return $label.'; '.$countLabel;
    }

    /**
     * @return array<string, string>
     */
    public function dwellBucketClassesByNode(ProcessGraphMetrics $metrics, MermaidProcessGraphRenderOptions $options): array
    {
        $valuesByNode = [];
        foreach ($metrics->nodes as $nodeId => $nodeMetrics) {
            if ($nodeMetrics->nodeType !== null && $nodeMetrics->nodeType !== ProcessGraphNode::TYPE_TASK && $nodeMetrics->nodeType !== 'task') {
                continue;
            }
            if (!$this->hasReliableDwell($nodeMetrics)) {
                continue;
            }
            $valuesByNode[$nodeId] = $options->dwellSeconds($nodeMetrics);
        }

        if ($valuesByNode === []) {
            return [];
        }

        if ($options->dwellScale === MermaidProcessGraphRenderOptions::DWELL_SCALE_FIXED_THRESHOLDS) {
            $classes = [];
            foreach ($valuesByNode as $nodeId => $seconds) {
                $classes[$nodeId] = $this->fixedDwellBucketClass($seconds);
            }

            return $classes;
        }

        return $this->relativeClassesByValue($valuesByNode, 'dwell-scale-', $options->dwellBuckets);
    }

    /**
     * @return array<string, string>
     */
    public function flowBucketClassesByNode(ProcessGraphMetrics $metrics, MermaidProcessGraphRenderOptions $options): array
    {
        $valuesByNode = [];
        foreach ($metrics->nodes as $nodeId => $nodeMetrics) {
            if ($nodeMetrics->flowCount === null || $nodeMetrics->flowCount <= 0) {
                continue;
            }
            $valuesByNode[$nodeId] = (float) $nodeMetrics->flowCount;
        }

        if ($valuesByNode === []) {
            return [];
        }

        return $this->relativeClassesByValue($valuesByNode, 'flow-scale-', $options->flowBuckets);
    }

    public function flowPercentile(ProcessGraphMetrics $metrics, float $percentile): ?float
    {
        $values = [];
        foreach ($metrics->nodes as $nodeMetrics) {
            if ($nodeMetrics->flowCount !== null && $nodeMetrics->flowCount > 0) {
                $values[] = (float) $nodeMetrics->flowCount;
            }
        }

        if ($values === []) {
            return null;
        }

        sort($values, SORT_NUMERIC);

        return $this->percentile($values, $percentile);
    }

    public function dwellPercentile(ProcessGraphMetrics $metrics, MermaidProcessGraphRenderOptions $options, float $percentile): ?float
    {
        $values = [];
        foreach ($metrics->nodes as $nodeMetrics) {
            if ($nodeMetrics->nodeType !== null && $nodeMetrics->nodeType !== ProcessGraphNode::TYPE_TASK && $nodeMetrics->nodeType !== 'task') {
                continue;
            }
            if ($this->hasReliableDwell($nodeMetrics)) {
                $values[] = $options->dwellSeconds($nodeMetrics);
            }
        }

        if ($values === []) {
            return null;
        }

        sort($values, SORT_NUMERIC);

        return $this->percentile($values, $percentile);
    }

    private function fixedDwellBucketClass(float $seconds): string
    {
        foreach (self::dwellBuckets() as $bucket) {
            if ($bucket['max_seconds'] === null || $seconds <= $bucket['max_seconds']) {
                return $bucket['class'];
            }
        }

        return 'dwell-critical';
    }

    private function hasReliableDwell(ProcessGraphNodeMetrics $metrics): bool
    {
        return ($metrics->reliableDwellCount ?? $metrics->observedCount) > 0;
    }

    /**
     * @param array<string, float> $valuesByNode
     * @return array<string, string>
     */
    private function relativeClassesByValue(array $valuesByNode, string $classPrefix, int $bucketCount): array
    {
        $values = array_values($valuesByNode);
        sort($values, SORT_NUMERIC);
        $p10 = $this->percentile($values, 10.0);
        $p90 = $this->percentile($values, 90.0);
        $maxBucket = max(0, $bucketCount - 1);
        $middleBucket = (int) floor($maxBucket / 2);

        $classes = [];
        foreach ($valuesByNode as $nodeId => $value) {
            if ($p10 === $p90) {
                $bucket = $middleBucket;
            } else {
                $clippedValue = min(max($value, $p10), $p90);
                $bucket = (int) floor((($clippedValue - $p10) / ($p90 - $p10)) * $maxBucket);
            }
            $classes[$nodeId] = $classPrefix.$bucket;
        }

        return $classes;
    }

    /**
     * @param list<float> $sortedValues
     */
    private function percentile(array $sortedValues, float $percentile): float
    {
        $count = count($sortedValues);
        if ($count === 1) {
            return $sortedValues[0];
        }

        $rank = (($percentile / 100.0) * ($count - 1));
        $lower = (int) floor($rank);
        $upper = (int) ceil($rank);
        if ($lower === $upper) {
            return $sortedValues[$lower];
        }

        $weight = $rank - $lower;

        return $sortedValues[$lower] + (($sortedValues[$upper] - $sortedValues[$lower]) * $weight);
    }

    private function linkStyle(?ProcessGraphEdgeMetrics $metrics, MermaidProcessGraphRenderOptions $options): ?string
    {
        if ($metrics === null) {
            return null;
        }

        $parts = [];
        if ($options->showsFlowMetrics() && $metrics->observedCount > 0) {
            $parts[] = 'stroke-width:'.$this->countStrokeWidth($metrics->observedCount).'px';
        }

        if ($options->showsDeviationMetrics() && ($metrics->isObservedOnly || $metrics->deviationCount > 0)) {
            $parts[] = 'stroke:#dc2626';
            $parts[] = 'stroke-dasharray: 5 5';
        }

        return $parts === [] ? null : implode(',', $parts);
    }

    private function countStrokeWidth(int $observedCount): int
    {
        if ($observedCount >= 5) {
            return 6;
        }
        if ($observedCount >= 2) {
            return 4;
        }

        return 2;
    }

    /**
     * @param array<string, true> $renderedEdgeKeys
     * @return array<int, ProcessGraphEdgeMetrics>
     */
    private function observedOnlyEdges(ProcessGraphMetrics $metrics, array $renderedEdgeKeys): array
    {
        $edges = [];
        foreach ($metrics->edges as $key => $edgeMetrics) {
            if (!$edgeMetrics->isObservedOnly || isset($renderedEdgeKeys[$key])) {
                continue;
            }

            $edges[] = $edgeMetrics;
        }

        usort(
            $edges,
            static fn (ProcessGraphEdgeMetrics $left, ProcessGraphEdgeMetrics $right): int => ($left->from <=> $right->from)
                ?: ($left->to <=> $right->to)
        );

        return $edges;
    }

    private function mermaidId(string $id): string
    {
        $normalized = preg_replace('/[^A-Za-z0-9_]+/', '_', $id) ?? '';
        $normalized = trim($normalized, '_');
        if ($normalized === '') {
            $normalized = substr(sha1($id), 0, 12);
        }

        return 'n_'.$normalized;
    }

    private function label(string $label): string
    {
        $label = str_replace(["\r\n", "\r", "\n"], '<br/>', $label);

        return str_replace('"', '&quot;', $label);
    }

    private function edgeLabel(string $label, MermaidProcessGraphRenderOptions $options): string
    {
        $label = $options->isObsidianCompatible() ? $this->obsidianLabel($label) : $label;
        // Encode comparison symbols as HTML entities so Mermaid does not treat
        // them as markup. They still render visually as >, <, >=, <=.
        $label = str_replace(['<', '>'], ['&lt;', '&gt;'], $label);
        $label = str_replace(["\r\n", "\r", "\n"], '<br/>', $label);
        $label = str_replace('|', '/', $label);
        $label = str_replace('"', '&quot;', $label);

        return '"'.$label.'"';
    }

    private function obsidianLabel(string $label): string
    {
        if ($label === '[else]') {
            return '(else)';
        }

        return preg_replace('/^\[(\d+)]\s*/', '($1) ', $label) ?? $label;
    }

}
