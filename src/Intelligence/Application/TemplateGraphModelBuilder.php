<?php

declare(strict_types=1);

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessGraphEdge;
use App\Intelligence\Domain\ProcessGraphNode;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateStep;

/** Translates APRIL template/findings data into process-graph's neutral v1 JSON contract. */
final readonly class TemplateGraphModelBuilder
{
    public function __construct(private ProcessTemplateGraphFactory $graphFactory)
    {
    }

    public function build(
        ProcessTemplate $template,
        ?TemplateGraphFindings $findings,
        string $documentsUrl
    ): TemplateGraphModel {
        $graph = $this->graphFactory->create($template);
        $steps = [];
        foreach ($template->steps as $step) {
            $steps[$step->key] = $step;
        }

        $nodes = [];
        $nodeStates = [];
        $markers = [];
        foreach ($graph->nodes as $node) {
            $step = $steps[$node->id] ?? null;
            $status = $this->nodeStatus($node, $findings);
            $state = $this->visualState($status);
            $required = $step instanceof ProcessTemplateStep
                ? ($template->scope === 'journey' ? $step->required : $node->required)
                : $node->required;
            $findingCount = $findings?->summaryFor($node->id)?->total ?? 0;
            $navigation = $this->nodeNavigation($node, $documentsUrl, $step);

            $data = [
                'required' => $required,
                'optional' => $step instanceof ProcessTemplateStep && !$required,
                'conditional' => $step instanceof ProcessTemplateStep && $step->when !== [],
                'metrics' => ['findingCount' => $findingCount],
            ];
            if ($step instanceof ProcessTemplateStep) {
                $data['stepType'] = $step->type;
                $data['processKey'] = $step->processKey;
            }
            if ($navigation !== null) {
                $data['navigation'] = ['url' => $navigation];
            }

            $nodes[] = [
                'id' => $node->id,
                'type' => $this->nodeType($node, $template, $step),
                'label' => $this->nodeType($node, $template, $step) === 'parallel'
                    ? ''
                    : $this->nodeLabel($node, $template, $step),
                'state' => $state,
                'description' => $this->nodeType($node, $template, $step) === 'parallel'
                    ? $node->label
                    : $this->nodeDescription($step, $required),
                'data' => array_filter($data, static fn (mixed $value): bool => $value !== null),
            ];
            if ($state !== 'normal') {
                $nodeStates[$node->id] = $state;
            }
            if ($findingCount > 0) {
                $markers[] = [
                    'targetId' => $node->id,
                    'kind' => 'node',
                    'severity' => $this->markerSeverity($status),
                    'label' => (string) $findingCount,
                    'description' => $findings?->summaryFor($node->id)?->label,
                ];
            }
        }

        foreach ($findings?->observedOnlyNodes ?? [] as $observed) {
            $nodes[] = [
                'id' => $observed->stepKey,
                'type' => 'activity',
                'label' => $observed->stepKey,
                'state' => 'deviation',
                'description' => $observed->stepKey,
                'data' => [
                    'required' => false,
                    'optional' => false,
                    'isExpected' => false,
                    'isObservedOnly' => true,
                    'metrics' => [
                        'itemCount' => $observed->itemCount,
                        'visitCount' => $observed->visitCount,
                        'deviationCount' => $observed->deviationCount,
                    ],
                ],
            ];
            $nodeStates[$observed->stepKey] = 'deviation';
            $markers[] = [
                'targetId' => $observed->stepKey,
                'kind' => 'node',
                'severity' => 'critical',
                'label' => (string) $observed->itemCount,
                'description' => 'Observed-only step',
            ];
        }

        $edges = [];
        $edgeStates = [];
        $edgeMarkers = [];
        $seenIds = [];
        foreach ($graph->edges as $index => $edge) {
            $edgeId = $this->edgeId($edge, $index, $seenIds);
            $finding = $this->transitionFinding($findings, $edge->from, $edge->to);
            $metric = $findings?->transitionMetricFor($edge->from, $edge->to);
            $targetStep = $steps[$edge->to] ?? null;
            $optional = $targetStep instanceof ProcessTemplateStep
                && ($template->scope === 'journey' ? !$targetStep->required : !$graph->nodes[$edge->to]->required);
            $state = $finding !== null ? 'deviation' : ($optional ? 'expected' : 'normal');
            $navigation = $finding !== null
                ? $this->url($documentsUrl, ['withFindings' => 1, 'transitionFrom' => $edge->from, 'transitionTo' => $edge->to])
                : null;

            $edges[] = [
                'id' => $edgeId,
                'from' => $edge->from,
                'to' => $edge->to,
                'label' => $this->edgeLabel($edge->label ?? $edge->condition, $metric),
                'state' => $state,
                'priority' => $edge->style === ProcessGraphEdge::STYLE_FLOW ? 1 : 0,
                'data' => array_filter([
                    'style' => $edge->style,
                    'optional' => $optional,
                    'metrics' => array_filter([
                        'documentCount' => $finding?->documentCount ?? 0,
                        'itemCount' => $metric?->itemCount,
                        'visitCount' => $metric?->visitCount,
                        'observedCount' => $metric?->observedCount,
                        'deviationCount' => $metric?->deviationCount,
                    ], static fn (mixed $value): bool => $value !== null),
                    'navigation' => $navigation === null ? null : ['url' => $navigation],
                ], static fn (mixed $value): bool => $value !== null),
            ];
            if ($state !== 'normal') {
                $edgeStates[$edgeId] = $state;
            }
            if ($finding !== null) {
                $edgeMarkers[] = [
                    'targetId' => $edgeId,
                    'kind' => 'edge',
                    'severity' => 'critical',
                    'label' => (string) $finding->count,
                    'description' => $finding->message,
                ];
            }
        }

        foreach ($findings?->transitionMetrics ?? [] as $metric) {
            if (!$metric->isObservedOnly) {
                continue;
            }
            $edge = new ProcessGraphEdge($metric->from, $metric->to);
            $edgeId = $this->edgeId($edge, count($edges), $seenIds);
            $edges[] = [
                'id' => $edgeId,
                'from' => $metric->from,
                'to' => $metric->to,
                'label' => $this->edgeLabel(null, $metric),
                'state' => 'deviation',
                'priority' => 1,
                'data' => [
                    'optional' => false,
                    'metrics' => [
                        'itemCount' => $metric->itemCount ?? $metric->observedCount,
                        'visitCount' => $metric->visitCount,
                        'observedCount' => $metric->observedCount,
                        'deviationCount' => $metric->deviationCount,
                        'isExpected' => false,
                        'isObservedOnly' => true,
                    ],
                ],
            ];
            $edgeStates[$edgeId] = 'deviation';
            $edgeMarkers[] = [
                'targetId' => $edgeId,
                'kind' => 'edge',
                'severity' => 'critical',
                'label' => (string) ($metric->itemCount ?? $metric->observedCount),
                'description' => 'Observed-only transition',
            ];
        }

        $overlay = [];
        if ($nodeStates !== [] || $edgeStates !== []) {
            $overlay['states'] = array_filter(['nodes' => $nodeStates, 'edges' => $edgeStates]);
        }
        if ($markers !== [] || $edgeMarkers !== []) {
            $overlay['markers'] = [...$markers, ...$edgeMarkers];
        }

        return new TemplateGraphModel(
            $template->scope === 'journey' ? 'journey' : 'process',
            $nodes,
            $edges,
            [
                'template' => ['key' => $template->key, 'version' => $template->version, 'scope' => $template->scope],
                'match' => ['anyProcess' => $template->match?->anyProcessKeys ?? []],
                'analysis' => [
                    'calculated' => $findings !== null,
                    'totalDocuments' => $findings?->totalDocuments ?? 0,
                    'processDeviations' => $findings?->processDeviations ?? 0,
                    'processWarnings' => $findings?->processWarnings ?? 0,
                    'processTechnical' => $findings?->processTechnical ?? 0,
                ],
            ],
            $overlay
        );
    }

    private function nodeType(ProcessGraphNode $node, ProcessTemplate $template, ?ProcessTemplateStep $step): string
    {
        return match ($node->type) {
            ProcessGraphNode::TYPE_START => 'start',
            ProcessGraphNode::TYPE_END => 'end',
            ProcessGraphNode::TYPE_EXCLUSIVE_GATEWAY => 'decision',
            ProcessGraphNode::TYPE_PARALLEL_GROUP,
            ProcessGraphNode::TYPE_PARALLEL_START,
            ProcessGraphNode::TYPE_PARALLEL_JOIN => 'parallel',
            default => $template->scope === 'journey' || $step?->type === 'process' ? 'subprocess' : 'activity',
        };
    }

    private function nodeLabel(ProcessGraphNode $node, ProcessTemplate $template, ?ProcessTemplateStep $step): string
    {
        if ($template->scope === 'journey' && $step?->processKey !== null) {
            return $step->name ?? $step->processKey;
        }

        return $node->label;
    }

    private function nodeStatus(ProcessGraphNode $node, ?TemplateGraphFindings $findings): string
    {
        if ($node->type === ProcessGraphNode::TYPE_EXCLUSIVE_GATEWAY) {
            return $findings?->gatewayStatusFor($node->id) ?? FindingSeverityFilter::NOT_CALCULATED;
        }
        if ($node->type !== ProcessGraphNode::TYPE_TASK) {
            return FindingSeverityFilter::NOT_CALCULATED;
        }

        return $findings === null
            ? FindingSeverityFilter::NOT_CALCULATED
            : ($findings->summaryFor($node->id)?->status ?? FindingSeverityFilter::OK);
    }

    private function visualState(string $status): string
    {
        return match ($status) {
            FindingSeverityFilter::CRITICAL => 'critical',
            FindingSeverityFilter::DEVIATION => 'deviation',
            FindingSeverityFilter::WARNING, FindingSeverityFilter::TECHNICAL => 'warning',
            FindingSeverityFilter::OK => 'satisfied',
            default => 'normal',
        };
    }

    private function markerSeverity(string $status): string
    {
        return in_array($status, [FindingSeverityFilter::CRITICAL, FindingSeverityFilter::DEVIATION], true)
            ? 'critical'
            : 'warning';
    }

    private function nodeDescription(?ProcessTemplateStep $step, bool $required): ?string
    {
        if (!$step instanceof ProcessTemplateStep) {
            return null;
        }
        $parts = [$required ? 'Required step' : 'Optional step'];
        if ($step->when !== []) {
            $parts[] = 'Conditional step';
        }
        if ($step->processKey !== null) {
            $parts[] = 'Process: '.$step->processKey;
        }

        return implode(' · ', $parts);
    }

    private function nodeNavigation(ProcessGraphNode $node, string $baseUrl, ?ProcessTemplateStep $step): ?string
    {
        if ($step instanceof ProcessTemplateStep) {
            return $this->url($baseUrl, ['withFindings' => 1, 'step' => $step->key]);
        }
        if ($node->type === ProcessGraphNode::TYPE_EXCLUSIVE_GATEWAY) {
            return $this->url($baseUrl, ['withFindings' => 1, 'decision' => substr($node->id, strlen('decision:'))]);
        }

        return null;
    }

    /** @param array<string, int|string> $query */
    private function url(string $baseUrl, array $query): string
    {
        return $baseUrl.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /** @param array<string, int> $seenIds */
    private function edgeId(ProcessGraphEdge $edge, int $index, array &$seenIds): string
    {
        $base = 'edge-'.substr(hash('sha256', implode("\0", [
            $edge->from,
            $edge->to,
            $edge->label ?? '',
            $edge->condition ?? '',
            $edge->style,
        ])), 0, 16);
        $occurrence = $seenIds[$base] ?? 0;
        $seenIds[$base] = $occurrence + 1;

        return $occurrence === 0 ? $base : $base.'-'.$index;
    }

    private function transitionFinding(?TemplateGraphFindings $findings, string $from, string $to): ?AttributedFinding
    {
        foreach ($findings?->attributedFindings ?? [] as $finding) {
            if ($finding->target === AttributedFinding::TARGET_TRANSITION
                && $finding->transitionFrom === $from
                && $finding->transitionTo === $to) {
                return $finding;
            }
        }

        return null;
    }

    private function knownGraphNode(ProcessGraph $graph, string $id): bool
    {
        return isset($graph->nodes[$id]);
    }

    private function edgeLabel(?string $label, ?\App\Intelligence\Domain\ProcessGraphEdgeMetrics $metric): ?string
    {
        if ($metric === null || $metric->itemCount === null) {
            return $label;
        }

        $count = (string) $metric->itemCount;

        return $label === null || trim($label) === '' ? $count : $label.' · '.$count;
    }
}
