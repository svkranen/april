<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessGraphEdge;
use App\Intelligence\Domain\ProcessGraphNode;
use App\Intelligence\Domain\ProcessTemplate;

/**
 * Builds a self-contained Mermaid flowchart for a single process template.
 *
 * The graph structure (step nodes, decision gateways, parallel groups, start/end
 * and all edges) is reused as-is from ProcessTemplateGraphFactory so the drawn
 * Soll-process matches the rest of the tooling. This builder only adds the
 * presentation: shapes per node type and, when findings are supplied, a status
 * class on each step (task) node derived from its aggregated findings.
 *
 * Pure / side-effect free - it takes already-aggregated findings and never reads
 * documents itself.
 */
final class TemplateMermaidGraphBuilder
{
    /** Findings status class -> fill/stroke; also defines the legend order. */
    public const CLASS_DEFS = [
        FindingSeverityFilter::CRITICAL => 'fill:#fce4ec,stroke:#880e4f',
        FindingSeverityFilter::DEVIATION => 'fill:#ffebee,stroke:#c62828',
        FindingSeverityFilter::WARNING => 'fill:#fff8e1,stroke:#f9a825',
        FindingSeverityFilter::TECHNICAL => 'fill:#ede7f6,stroke:#4527a0',
        FindingSeverityFilter::OK => 'fill:#e8f5e9,stroke:#2e7d32',
        FindingSeverityFilter::NOT_CALCULATED => 'fill:#eeeeee,stroke:#757575',
    ];

    /** Neutral class for non-step structure nodes (start/end/gateways/parallel). */
    public const STRUCTURE_CLASS = 'structure';

    public function __construct(private readonly ProcessTemplateGraphFactory $graphFactory)
    {
    }

    public function build(ProcessTemplate $template, ?TemplateGraphFindings $findings): string
    {
        $graph = $this->graphFactory->create($template);

        $nodeIds = array_keys($graph->nodes);
        sort($nodeIds);

        $lines = ['flowchart TD'];
        $classByNode = [];
        foreach ($nodeIds as $nodeId) {
            $node = $graph->nodes[$nodeId];
            $mermaidId = $this->mermaidId($node->id);
            [$status, $secondLine] = $this->nodeStatus($node, $findings);
            $classByNode[$mermaidId] = $status;
            $lines[] = '    '.$mermaidId.$this->shape($node, $secondLine);
        }

        $lines[] = '';
        foreach ($graph->edges as $edge) {
            $lines[] = '    '.$this->edge($edge);
        }

        $lines[] = '';
        foreach ($classByNode as $mermaidId => $status) {
            $lines[] = sprintf('    class %s %s', $mermaidId, $status);
        }

        $lines[] = '';
        foreach (self::CLASS_DEFS as $status => $style) {
            $lines[] = sprintf('    classDef %s %s', $status, $style);
        }
        $lines[] = '    classDef '.self::STRUCTURE_CLASS.' fill:#f5f5f5,stroke:#90a4ae';

        return implode("\n", $lines)."\n";
    }

    /**
     * @return array{0: string, 1: ?string} status class + optional second label line
     */
    private function nodeStatus(ProcessGraphNode $node, ?TemplateGraphFindings $findings): array
    {
        // Decision gateways are coloured only when a decision finding was attributed
        // to this exact gateway node (stable, id-based - no fragile edge styling).
        if ($node->type === ProcessGraphNode::TYPE_EXCLUSIVE_GATEWAY) {
            $gatewayStatus = $findings?->gatewayStatusFor($node->id);

            return [$gatewayStatus ?? self::STRUCTURE_CLASS, null];
        }

        // Only step (task) nodes carry their own findings; other structure stays neutral.
        if ($node->type !== ProcessGraphNode::TYPE_TASK) {
            return [self::STRUCTURE_CLASS, null];
        }

        if ($findings === null) {
            return [FindingSeverityFilter::NOT_CALCULATED, null];
        }

        $summary = $findings->summaryFor($node->id);
        if ($summary === null) {
            return [FindingSeverityFilter::OK, FindingSeverityFilter::label(FindingSeverityFilter::OK)];
        }

        return [$summary->status, $summary->label];
    }

    private function shape(ProcessGraphNode $node, ?string $secondLine): string
    {
        $label = $this->label($node->label, $node->type === ProcessGraphNode::TYPE_TASK ? $secondLine : null);

        return match ($node->type) {
            ProcessGraphNode::TYPE_START, ProcessGraphNode::TYPE_END => sprintf('(("%s"))', $label),
            ProcessGraphNode::TYPE_EXCLUSIVE_GATEWAY => sprintf('{"%s"}', $label),
            ProcessGraphNode::TYPE_PARALLEL_GROUP => sprintf('[["%s"]]', $label),
            ProcessGraphNode::TYPE_PARALLEL_START, ProcessGraphNode::TYPE_PARALLEL_JOIN => sprintf('{{"%s"}}', $label),
            default => sprintf('["%s"]', $label),
        };
    }

    private function edge(ProcessGraphEdge $edge): string
    {
        $from = $this->mermaidId($edge->from);
        $to = $this->mermaidId($edge->to);
        $arrow = ($edge->style === ProcessGraphEdge::STYLE_CONSTRAINT || $edge->style === ProcessGraphEdge::STYLE_IMPLICIT)
            ? '-.->'
            : '-->';

        $label = $edge->label ?? $edge->condition;
        if ($label === null || trim($label) === '') {
            return sprintf('%s %s %s', $from, $arrow, $to);
        }

        return sprintf('%s %s|"%s"| %s', $from, $arrow, $this->escape(str_replace('|', '/', $label)), $to);
    }

    private function label(string $label, ?string $secondLine): string
    {
        $text = $this->escape($label);
        if ($secondLine !== null && $secondLine !== '') {
            $text .= '<br/>'.$this->escape($secondLine);
        }

        return $text;
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

    private function escape(string $label): string
    {
        $label = str_replace(["\r\n", "\r", "\n"], '<br/>', $label);

        return str_replace('"', '&quot;', $label);
    }
}
