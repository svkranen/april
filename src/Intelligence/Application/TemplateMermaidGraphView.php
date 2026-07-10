<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessTemplate;

/**
 * Transitional graph-page read model. Mermaid remains the fallback while the
 * neutral process-graph model is introduced; Twig receives both server-built
 * representations and never traverses domain objects.
 */
final readonly class TemplateMermaidGraphView
{
    /**
     * @param array<int, array{key: string, name: string, status: string, statusLabel: string, findingsLabel: string, hasFindings: bool}> $steps
     * @param array<int, array{status: string, label: string}> $legend
     * @param array<int, AttributedFinding> $transitionDecisionFindings findings attributed to a gateway or transition edge
     */
    public function __construct(
        public string $key,
        public string $version,
        public bool $withFindings,
        public string $mermaidCode,
        public array $steps,
        public array $legend,
        public int $totalDocuments,
        public int $processedDocuments,
        public bool $limitReached,
        public int $findingsLimit,
        public int $processDeviations,
        public int $processWarnings,
        public int $processTechnical,
        public array $transitionDecisionFindings = [],
        public array $processGraphModel = [],
        public string $processGraphJson = '{}',
        public string $renderer = 'mermaid',
        public string $processGraphModuleUrl = '/vendor/process-graph/index.js',
        public string $direction = 'TB'
    ) {
    }

    public static function build(
        ProcessTemplate $template,
        bool $withFindings,
        ?TemplateGraphFindings $findings,
        string $mermaidCode,
        int $findingsLimit,
        ?TemplateGraphModel $processGraphModel = null,
        string $renderer = 'mermaid',
        string $processGraphModuleUrl = '/vendor/process-graph/index.js',
        string $direction = 'TB'
    ): self {
        $steps = [];
        foreach ($template->steps as $step) {
            $summary = $findings?->summaryFor($step->key);
            $status = $summary?->status ?? FindingSeverityFilter::NOT_CALCULATED;
            $steps[] = [
                'key' => $step->key,
                'name' => $step->name ?? $step->key,
                'status' => $status,
                'statusLabel' => FindingSeverityFilter::label($status),
                'findingsLabel' => $withFindings ? ($summary?->label ?? FindingSeverityFilter::label(FindingSeverityFilter::OK)) : '—',
                'hasFindings' => $withFindings && ($summary?->total ?? 0) > 0,
            ];
        }

        $legend = [];
        foreach (array_keys(TemplateMermaidGraphBuilder::CLASS_DEFS) as $status) {
            $legend[] = ['status' => $status, 'label' => FindingSeverityFilter::label($status)];
        }

        return new self(
            $template->key,
            $template->version,
            $withFindings,
            $mermaidCode,
            $steps,
            $legend,
            $findings?->totalDocuments ?? 0,
            $findings?->processedDocuments ?? 0,
            $findings?->limitReached ?? false,
            $findingsLimit,
            $findings?->processDeviations ?? 0,
            $findings?->processWarnings ?? 0,
            $findings?->processTechnical ?? 0,
            $findings?->attributedFindings ?? [],
            $processGraphModel?->jsonSerialize() ?? [],
            $processGraphModel?->toJson() ?? '{}',
            $renderer,
            $processGraphModuleUrl,
            $direction
        );
    }

    public function hasProcessFindings(): bool
    {
        return $this->processDeviations > 0 || $this->processWarnings > 0 || $this->processTechnical > 0;
    }

    public function hasTransitionDecisionFindings(): bool
    {
        return $this->transitionDecisionFindings !== [];
    }
}
