<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessDeviation;

/**
 * Conservative, structure-only attribution of a process deviation to the graph.
 *
 * Works exclusively from the typed fields a {@see ProcessDeviation} carries - it
 * never parses the human-readable message. When a deviation cannot be mapped to
 * an existing decision gateway or to a complete observed transition, it stays
 * process-wide. This is intentional: an uncertain attribution is treated as no
 * attribution rather than guessing.
 *
 * Pure / side-effect free.
 */
final class GraphFindingAttribution
{
    /**
     * @param array<string, bool> $gatewayNodeIds set of decision gateway node ids that actually exist in the graph (keys), e.g. ['decision:approval' => true]
     */
    public function attribute(ProcessDeviation $deviation, array $gatewayNodeIds): FindingAttribution
    {
        return match ($deviation->type) {
            ProcessDeviation::TYPE_DECISION_RULE_VIOLATION => $this->attributeDecision($deviation, $gatewayNodeIds),
            ProcessDeviation::TYPE_TRANSITION_VIOLATION => $this->attributeTransition($deviation),
            default => FindingAttribution::process(),
        };
    }

    /**
     * @param array<string, bool> $gatewayNodeIds
     */
    private function attributeDecision(ProcessDeviation $deviation, array $gatewayNodeIds): FindingAttribution
    {
        if ($deviation->decisionKey === null) {
            return FindingAttribution::process();
        }

        // Attribute to the gateway only when the graph actually draws it; otherwise
        // there is nothing to mark and the finding stays process-wide.
        $nodeId = ProcessTemplateGraphFactory::gatewayNodeId($deviation->decisionKey);
        if (!isset($gatewayNodeIds[$nodeId])) {
            return FindingAttribution::process();
        }

        return FindingAttribution::gateway($nodeId);
    }

    private function attributeTransition(ProcessDeviation $deviation): FindingAttribution
    {
        // Need both endpoints of the observed (Ist) transition to name an edge.
        if ($deviation->from === null || $deviation->from === '' || $deviation->actual === null || $deviation->actual === '') {
            return FindingAttribution::process();
        }

        return FindingAttribution::transition($deviation->from, $deviation->actual);
    }
}
