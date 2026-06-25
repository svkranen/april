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

    /**
     * @param ?string $decisionKey    set for gateway findings - links to ?decision=<decisionKey>
     * @param ?string $transitionFrom set for transition findings - links to ?transitionFrom=<from>
     * @param ?string $transitionTo   set for transition findings - links to ?transitionTo=<actual>
     */
    public function __construct(
        public string $target,
        public string $label,
        public string $severity,
        public string $severityLabel,
        public string $message,
        public int $count,
        public int $documentCount,
        public ?string $decisionKey = null,
        public ?string $transitionFrom = null,
        public ?string $transitionTo = null
    ) {
    }

    public function targetLabel(): string
    {
        return $this->target === self::TARGET_GATEWAY ? 'Gateway' : 'Kante';
    }

    public function hasDocuments(): bool
    {
        return $this->documentCount > 0;
    }

    public function isGateway(): bool
    {
        return $this->target === self::TARGET_GATEWAY;
    }
}
