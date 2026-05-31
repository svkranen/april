<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\EnrichedProcessGraph;
use App\Intelligence\Domain\ProcessGraph;
use App\Intelligence\Domain\ProcessGraphEdge;
use App\Intelligence\Domain\ProcessGraphEdgeMetrics;
use App\Intelligence\Domain\ProcessGraphMetrics;
use App\Intelligence\Domain\ProcessGraphNodeMetrics;
use App\Intelligence\Domain\ProcessTemplate;

final class ProcessGraphMetricsFactory
{
    public function __construct(
        private readonly ProcessGraphObservationProjector $observationProjector = new ProcessGraphObservationProjector()
    ) {
    }

    /**
     * @param array<string, mixed>|null $report
     */
    public function enrich(ProcessGraph $graph, ?array $report = null, ?ProcessTemplate $template = null): EnrichedProcessGraph
    {
        return new EnrichedProcessGraph($graph, $this->fromReport($graph, $report, $template));
    }

    /**
     * @param array<string, mixed>|null $report
     */
    public function fromReport(ProcessGraph $graph, ?array $report = null, ?ProcessTemplate $template = null): ProcessGraphMetrics
    {
        $edgeMetrics = $this->expectedEdgeMetrics($graph);

        foreach ($this->flowTransitions($report) as $transition) {
            $from = $this->stringValue($transition['from'] ?? null);
            $to = $this->stringValue($transition['to'] ?? null);
            if ($from === null || $to === null) {
                continue;
            }

            $observedCount = $this->intValue($transition['count'] ?? null);
            if ($observedCount <= 0) {
                continue;
            }

            if ($template !== null) {
                $projection = $this->observationProjector->project(
                    $graph,
                    $template,
                    $from,
                    $to,
                    is_array($transition['context'] ?? null) ? $transition['context'] : null
                );

                if (!$projection->isUnexpected()) {
                    foreach ($projection->projectedEdges as [$projectedFrom, $projectedTo]) {
                        $this->addObservedCount($edgeMetrics, $projectedFrom, $projectedTo, $observedCount, 0, true, false);
                    }

                    continue;
                }
            }

            $key = ProcessGraphMetrics::edgeKey($from, $to);
            $isExpected = isset($edgeMetrics[$key]);
            $deviationCount = $this->intValue($transition['deviation_count'] ?? null);
            if (!$isExpected && $deviationCount === 0) {
                $deviationCount = $observedCount;
            }

            $this->addObservedCount(
                $edgeMetrics,
                $from,
                $to,
                $observedCount,
                $deviationCount,
                $isExpected,
                !$isExpected
            );
        }

        return new ProcessGraphMetrics($this->nodeMetrics($graph, $report), $edgeMetrics);
    }

    /**
     * @param array<string, ProcessGraphEdgeMetrics> $edgeMetrics
     */
    private function addObservedCount(array &$edgeMetrics, string $from, string $to, int $observedCount, int $deviationCount, bool $isExpected, bool $isObservedOnly): void
    {
        $key = ProcessGraphMetrics::edgeKey($from, $to);
        $existing = $edgeMetrics[$key] ?? null;

        $edgeMetrics[$key] = new ProcessGraphEdgeMetrics(
            $from,
            $to,
            ($existing?->observedCount ?? 0) + $observedCount,
            ($existing?->deviationCount ?? 0) + $deviationCount,
            $isExpected,
            $isObservedOnly
        );
    }

    /**
     * @return array<string, ProcessGraphEdgeMetrics>
     */
    private function expectedEdgeMetrics(ProcessGraph $graph): array
    {
        $metrics = [];
        foreach ($graph->edges as $edge) {
            if ($edge->style !== ProcessGraphEdge::STYLE_FLOW) {
                continue;
            }

            $metrics[ProcessGraphMetrics::edgeKey($edge->from, $edge->to)] = new ProcessGraphEdgeMetrics($edge->from, $edge->to);
        }

        return $metrics;
    }

    /**
     * @param array<string, mixed>|null $report
     * @return array<int, array<string, mixed>>
     */
    private function flowTransitions(?array $report): array
    {
        $transitions = $report['flow_heatmap']['transitions'] ?? [];

        return is_array($transitions) ? $transitions : [];
    }

    /**
     * @param array<string, mixed>|null $report
     * @return array<string, ProcessGraphNodeMetrics>
     */
    private function nodeMetrics(ProcessGraph $graph, ?array $report): array
    {
        $steps = $report['duration_heatmap']['steps'] ?? [];
        if (!is_array($steps)) {
            $steps = [];
        }

        $metrics = [];
        $startStepKeys = $this->startStepKeys($graph);
        foreach ($steps as $step) {
            if (!is_array($step)) {
                continue;
            }

            $stepKey = $this->stringValue($step['step'] ?? null);
            if ($stepKey === null) {
                continue;
            }

            $historical = is_array($step['historical'] ?? null) ? $step['historical'] : [];
            $current = is_array($step['current'] ?? null) ? $step['current'] : [];
            $completedDocuments = $this->intValue($historical['completed_documents'] ?? null);
            $openDocuments = $this->intValue($current['open_documents'] ?? null);
            $reliableDwellCount = $this->reliableDwellCount($step, $completedDocuments);
            if (isset($startStepKeys[$stepKey]) && !array_key_exists('reliable_dwell_count', $step) && !array_key_exists('has_reliable_dwell', $step)) {
                $reliableDwellCount = 0;
            }

            $metrics[$stepKey] = new ProcessGraphNodeMetrics(
                $completedDocuments + $openDocuments,
                $this->minutesToSeconds($historical['avg_duration_minutes'] ?? null),
                $this->minutesToSeconds($historical['median_duration_minutes'] ?? null),
                $this->minutesToSeconds($historical['p95_duration_minutes'] ?? $historical['max_duration_minutes'] ?? null),
                $this->intValue($step['deviation_count'] ?? null),
                'task',
                $reliableDwellCount
            );
        }

        foreach ($this->virtualNodeDurations($report) as $node) {
            $nodeKey = $this->stringValue($node['node_key'] ?? $node['nodeKey'] ?? null);
            if ($nodeKey === null) {
                continue;
            }

            $durations = $this->numberList($node['durations_seconds'] ?? $node['durationsSeconds'] ?? []);
            if ($durations === []) {
                continue;
            }

            $metrics[$nodeKey] = new ProcessGraphNodeMetrics(
                count($durations),
                $this->average($durations),
                $this->median($durations),
                $this->percentile($durations, 95.0),
                $this->intValue($node['deviation_count'] ?? null),
                $this->stringValue($node['node_type'] ?? $node['nodeType'] ?? null),
                count($durations)
            );
        }

        foreach ($this->nodeFlowMetrics($report) as $node) {
            $nodeKey = $this->stringValue($node['node_key'] ?? $node['nodeKey'] ?? null);
            if ($nodeKey === null) {
                continue;
            }

            $flowCount = $this->intValue($node['count'] ?? $node['flow_count'] ?? $node['flowCount'] ?? null);
            if ($flowCount <= 0) {
                continue;
            }

            $existing = $metrics[$nodeKey] ?? null;
            $metrics[$nodeKey] = new ProcessGraphNodeMetrics(
                $existing?->observedCount ?? $flowCount,
                $existing?->avgDwellSeconds ?? 0.0,
                $existing?->medianDwellSeconds ?? 0.0,
                $existing?->p95DwellSeconds ?? 0.0,
                $existing?->deviationCount ?? $this->intValue($node['deviation_count'] ?? null),
                $existing?->nodeType ?? $this->stringValue($node['node_type'] ?? $node['nodeType'] ?? null),
                $existing?->reliableDwellCount,
                $flowCount
            );
        }

        return $metrics;
    }

    /**
     * @param array<string, mixed>|null $report
     * @return array<int, array<string, mixed>>
     */
    private function virtualNodeDurations(?array $report): array
    {
        $nodes = $report['virtual_node_durations']['nodes'] ?? $report['node_durations']['nodes'] ?? [];

        return is_array($nodes) ? $nodes : [];
    }

    /**
     * @param array<string, mixed>|null $report
     * @return array<int, array<string, mixed>>
     */
    private function nodeFlowMetrics(?array $report): array
    {
        $nodes = $report['node_flow']['nodes'] ?? $report['flow_nodes']['nodes'] ?? [];

        return is_array($nodes) ? $nodes : [];
    }

    /**
     * @return array<string, true>
     */
    private function startStepKeys(ProcessGraph $graph): array
    {
        $keys = [];
        foreach ($graph->edges as $edge) {
            if ($edge->from === '__start') {
                $keys[$edge->to] = true;
            }
        }

        return $keys;
    }

    /**
     * @param array<string, mixed> $step
     */
    private function reliableDwellCount(array $step, int $completedDocuments): int
    {
        if (array_key_exists('reliable_dwell_count', $step)) {
            return $this->intValue($step['reliable_dwell_count']);
        }

        if (array_key_exists('has_reliable_dwell', $step)) {
            return $step['has_reliable_dwell'] === true ? $completedDocuments : 0;
        }

        return $completedDocuments;
    }

    private function stringValue(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function intValue(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    private function minutesToSeconds(mixed $value): float
    {
        return is_numeric($value) ? max(0.0, (float) $value * 60.0) : 0.0;
    }

    /**
     * @return list<float>
     */
    private function numberList(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }

        $numbers = [];
        foreach ($values as $value) {
            if (is_numeric($value)) {
                $numbers[] = max(0.0, (float) $value);
            }
        }

        return $numbers;
    }

    /**
     * @param list<float> $values
     */
    private function average(array $values): float
    {
        return $values === [] ? 0.0 : array_sum($values) / count($values);
    }

    /**
     * @param list<float> $values
     */
    private function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values, SORT_NUMERIC);
        $middle = intdiv(count($values), 2);
        if (count($values) % 2 === 1) {
            return $values[$middle];
        }

        return ($values[$middle - 1] + $values[$middle]) / 2.0;
    }

    /**
     * @param list<float> $values
     */
    private function percentile(array $values, float $percentile): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values, SORT_NUMERIC);
        if (count($values) === 1) {
            return $values[0];
        }

        $rank = ($percentile / 100.0) * (count($values) - 1);
        $lower = (int) floor($rank);
        $upper = (int) ceil($rank);
        if ($lower === $upper) {
            return $values[$lower];
        }

        return $values[$lower] + (($values[$upper] - $values[$lower]) * ($rank - $lower));
    }
}
