<?php

namespace App\Intelligence\Application;

/**
 * Compact per-row findings summary for the document list. A trimmed projection
 * of DocumentFindingsView - only what a list row needs (overall status + counts).
 */
final readonly class DocumentListFindingView
{
    /**
     * @param array<string, int> $countsByCategory
     * @param array<int, string> $stepKeys distinct step keys with a step-attributable finding
     * @param array<int, string> $decisionKeys distinct decision keys whose gateway carries an attributed finding
     * @param array<int, string> $transitionKeys distinct attributed transitions, each as "from\0to"
     */
    public function __construct(
        public string $documentUuid,
        public string $severity,
        public string $label,
        public string $cssClass,
        public int $total,
        public array $countsByCategory,
        public ?string $error,
        public array $stepKeys = [],
        public array $decisionKeys = [],
        public array $transitionKeys = []
    ) {
    }

    /**
     * @param array<int, string> $decisionKeys
     * @param array<int, string> $transitionKeys
     */
    public static function fromFindings(
        string $documentUuid,
        DocumentFindingsView $findings,
        array $decisionKeys = [],
        array $transitionKeys = []
    ): self {
        return new self(
            $documentUuid,
            $findings->overallSeverity,
            $findings->overallLabel,
            $findings->overallCssClass,
            $findings->total,
            $findings->countsByCategory,
            null,
            self::distinctStepKeys($findings),
            $decisionKeys,
            $transitionKeys
        );
    }

    public static function failed(string $documentUuid, string $error): self
    {
        return new self(
            $documentUuid,
            DocumentFindingsView::SEVERITY_TECHNICAL,
            'Technisch',
            'vs-unknown',
            0,
            ['process' => 0, 'context' => 0, 'access' => 0, 'technical' => 0],
            $error
        );
    }

    public function hasStep(string $stepKey): bool
    {
        return in_array($stepKey, $this->stepKeys, true);
    }

    public function hasDecision(string $decisionKey): bool
    {
        return in_array($decisionKey, $this->decisionKeys, true);
    }

    public function hasTransition(string $from, string $to): bool
    {
        return in_array(self::transitionKey($from, $to), $this->transitionKeys, true);
    }

    /**
     * Stable key for an attributed transition; the null byte cannot occur in a
     * step key, so it is a safe separator.
     */
    public static function transitionKey(string $from, string $to): string
    {
        return $from."\0".$to;
    }

    /**
     * @return array<int, string>
     */
    private static function distinctStepKeys(DocumentFindingsView $findings): array
    {
        $stepKeys = [];
        foreach ($findings->findings as $finding) {
            $stepKey = $finding['stepKey'] ?? null;
            if ($stepKey !== null && !in_array($stepKey, $stepKeys, true)) {
                $stepKeys[] = $stepKey;
            }
        }

        return $stepKeys;
    }
}
