<?php

namespace App\Intelligence\Application;

/**
 * One aggregated transition/decision finding attributed to a graph anchor
 * (a decision gateway or an observed transition edge), ready for the
 * "Übergangs-/Entscheidungs-Findings" section. Twig-friendly, no logic.
 */
final readonly class AttributedFinding
{
    public const TARGET_GATEWAY = FindingAttribution::TARGET_GATEWAY;
    public const TARGET_TRANSITION = FindingAttribution::TARGET_TRANSITION;

    public function __construct(
        public string $target,
        public string $label,
        public string $severity,
        public string $severityLabel,
        public string $message,
        public int $count,
        public int $documentCount
    ) {
    }

    public function targetLabel(): string
    {
        return $this->target === self::TARGET_GATEWAY ? 'Gateway' : 'Kante';
    }
}
