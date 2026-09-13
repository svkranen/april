<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessGraph;
use App\Intelligence\Domain\ProcessGraphEdge;
use App\Intelligence\Domain\ProcessGraphEdgeMetrics;
use App\Intelligence\Domain\ProcessGraphMetrics;
use App\Intelligence\Domain\ProcessGraphNodeMetrics;
use App\Intelligence\Domain\ProcessRunMeasurement;
use App\Intelligence\Domain\ProcessTemplate;

/** Projects factual KPI transitions onto one explicitly selected template graph. */
final readonly class ProcessGraphTransitionMetricsBuilder
{
    public function __construct(
        private ProcessTransitionAggregator $aggregator = new ProcessTransitionAggregator(),
        private ProcessTemplateProvider $templates = new NullProcessTemplateProvider(),
        private ProcessTemplateGraphFactory $graphFactory = new ProcessTemplateGraphFactory(),
        private ProcessGraphObservationProjector $projector = new ProcessGraphObservationProjector()
    ) {
    }

    /** @param list<ProcessRunMeasurement> $runs @return list<ProcessGraphEdgeMetrics> */
    public function build(ProcessTemplate $template, array $runs): array
    {
        $graph = $this->graphFactory->create($template);
        $metrics = $this->expectedEdges($graph);
        $selectedRuns = $this->selectedRuns($template, $runs);

        foreach ($this->aggregator->aggregate($selectedRuns) as $aggregate) {
            $projection = $this->projector->project($graph, $template, $aggregate->fromStep, $aggregate->toStep);
            if ($projection->isUnexpected()) {
                $this->add($metrics, $aggregate->fromStep, $aggregate->toStep, $aggregate->itemCount, $aggregate->visitCount, false, true);
                continue;
            }

            foreach ($projection->projectedEdges as [$from, $to]) {
                $this->add($metrics, $from, $to, $aggregate->itemCount, $aggregate->visitCount, true, false);
            }
        }

        return array_values($metrics);
    }

    /** @param list<ProcessRunMeasurement> $runs @return list<ProcessGraphNodeMetrics> */
    public function buildObservedOnlyNodes(ProcessTemplate $template, array $runs): array
    {
        $graph = $this->graphFactory->create($template);
        $known = array_fill_keys(array_keys($graph->nodes), true);
        $items = [];
        $visits = [];
        foreach ($this->selectedRuns($template, $runs) as $run) {
            $seen = [];
            foreach ($run->observedStepSequence as $visit) {
                if (isset($known[$visit->stepKey])) {
                    continue;
                }
                $visits[$visit->stepKey] = ($visits[$visit->stepKey] ?? 0) + 1;
                $seen[$visit->stepKey] = true;
            }
            foreach (array_keys($seen) as $stepKey) {
                $items[$stepKey] = ($items[$stepKey] ?? 0) + 1;
            }
        }
        $result = [];
        foreach ($visits as $stepKey => $count) {
            $result[] = new ProcessGraphNodeMetrics(observedCount: $items[$stepKey] ?? 0, deviationCount: $items[$stepKey] ?? 0, itemCount: $items[$stepKey] ?? 0, visitCount: $count, isExpected: false, isObservedOnly: true, stepKey: $stepKey);
        }
        usort($result, static fn (ProcessGraphNodeMetrics $a, ProcessGraphNodeMetrics $b): int => $a->stepKey <=> $b->stepKey);
        return $result;
    }

    /** @param list<ProcessRunMeasurement> $runs @return list<ProcessGraphDeviationChain> */
    public function buildDeviationChains(ProcessTemplate $template, array $runs): array
    {
        $graph = $this->graphFactory->create($template);
        $chains = [];
        foreach ($this->selectedRuns($template, $runs) as $run) {
            $current = [];
            foreach ($run->observedTransitions as $transition) {
                $projection = $this->projector->project($graph, $template, $transition->fromStep, $transition->toStep);
                if ($projection->isUnexpected()) {
                    $current[] = ['from' => $transition->fromStep, 'to' => $transition->toStep];
                    continue;
                }
                if ($current !== []) {
                    $chains[] = new ProcessGraphDeviationChain($run->key, $current, true, false, false);
                    $current = [];
                }
            }
            if ($current !== []) {
                $chains[] = new ProcessGraphDeviationChain(
                    $run->key,
                    $current,
                    false,
                    $run->status === 'running',
                    $run->status === 'completed'
                );
            }
        }
        return $chains;
    }

    /** @return list<ProcessRunMeasurement> */
    private function selectedRuns(ProcessTemplate $template, array $runs): array
    {
        return array_values(array_filter($runs, function (ProcessRunMeasurement $run) use ($template): bool {
            return $run->historicalTemplateVersion === $template->version
                && $this->templates instanceof ProcessTemplateVersionProvider
                && $this->templates->findByProcessKeyAndVersion($run->processKey, $run->historicalTemplateVersion) !== null;
        }));
    }

    /** @return array<string, ProcessGraphEdgeMetrics> */
    private function expectedEdges(ProcessGraph $graph): array
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

    /** @param array<string, ProcessGraphEdgeMetrics> $metrics */
    private function add(array &$metrics, string $from, string $to, int $items, int $visits, bool $expected, bool $observedOnly): void
    {
        $key = ProcessGraphMetrics::edgeKey($from, $to);
        $existing = $metrics[$key] ?? null;
        $metrics[$key] = new ProcessGraphEdgeMetrics(
            $from,
            $to,
            ($existing?->observedCount ?? 0) + $items,
            ($existing?->deviationCount ?? 0) + ($expected ? 0 : $items),
            $expected,
            $observedOnly,
            ($existing?->itemCount ?? 0) + $items,
            ($existing?->visitCount ?? 0) + $visits
        );
    }

    private function knownNode(ProcessGraph $graph, string $id): bool
    {
        return isset($graph->nodes[$id]);
    }
}

/** Keeps the optional versioned provider dependency safe for existing callers/tests. */
final readonly class NullProcessTemplateProvider implements ProcessTemplateProvider
{
    public function findByProcessKey(string $processKey): ?ProcessTemplate
    {
        return null;
    }
}
