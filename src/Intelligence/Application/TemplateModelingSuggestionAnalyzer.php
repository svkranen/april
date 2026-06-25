<?php

namespace App\Intelligence\Application;

/**
 * Derives business-level modelling suggestions from the already-aggregated graph
 * findings. It only reuses the structured {@see AttributedFinding} data and the
 * process-wide counters - it never parses finding messages and never changes the
 * template. When findings were not computed it returns the "not computed" view so
 * the page can show a hint instead of running an expensive runtime check.
 *
 * Pure / side-effect free.
 */
final class TemplateModelingSuggestionAnalyzer
{
    public function fromFindings(?TemplateGraphFindings $findings): TemplateModelingSuggestionsView
    {
        if ($findings === null) {
            return TemplateModelingSuggestionsView::notComputed();
        }

        $suggestions = [];

        // 1 + 2: transition and decision deviations attributed to an edge/gateway.
        foreach ($findings->attributedFindings as $finding) {
            $suggestions[] = $finding->isGateway()
                ? $this->decisionSuggestion($finding)
                : $this->transitionSuggestion($finding);
        }

        // 5: process-wide findings that could not be attributed to step/gateway/edge.
        $processCount = $findings->processDeviations + $findings->processWarnings + $findings->processTechnical;
        if ($processCount > 0) {
            $suggestions[] = $this->processWideSuggestion($findings, $processCount);
        }

        return new TemplateModelingSuggestionsView(
            true,
            $suggestions,
            $findings->totalDocuments,
            $findings->processedDocuments,
            $findings->limitReached
        );
    }

    private function decisionSuggestion(AttributedFinding $finding): TemplateModelingSuggestion
    {
        return new TemplateModelingSuggestion(
            'decision_rule_violation',
            'Decision-Regel verletzt',
            TemplateModelingSuggestion::STATUS_REVIEW,
            sprintf('Am Entscheidungs-Gateway „%s" weicht der beobachtete Pfad vom Soll ab.', $finding->label),
            'Regel oder Kontextdaten prüfen: Soll-Regel anpassen oder Datenqualität klären – keine automatische Änderung.',
            $finding->documentCount
        );
    }

    private function transitionSuggestion(AttributedFinding $finding): TemplateModelingSuggestion
    {
        return new TemplateModelingSuggestion(
            'observed_transition_deviation',
            'Beobachtete Transition weicht vom Soll ab',
            TemplateModelingSuggestion::STATUS_REVIEW,
            sprintf('Der beobachtete Übergang „%s" ist im Soll nicht erlaubt.', $finding->label),
            'Prüfen, ob Rücksprung, Sonderfall oder Testdokument vorliegt – oder ob eine neue Transition ins Soll gehört.',
            $finding->documentCount,
            // Read-only sketch of the transition one could add to allow this path.
            TemplateYamlDiffPreview::forTransitionDeviation($finding->transitionFrom, $finding->transitionTo)
        );
    }

    private function processWideSuggestion(TemplateGraphFindings $findings, int $processCount): TemplateModelingSuggestion
    {
        return new TemplateModelingSuggestion(
            'process_wide_findings',
            'Nicht zuordenbare prozessweite Findings',
            $findings->processDeviations > 0
                ? TemplateModelingSuggestion::STATUS_REVIEW
                : TemplateModelingSuggestion::STATUS_OPTIONAL,
            sprintf(
                '%d prozessweite Finding(s) ohne eindeutigen Schritt, Gateway oder Kante (%d Abweichung(en), %d Warnung(en), %d technisch).',
                $processCount,
                $findings->processDeviations,
                $findings->processWarnings,
                $findings->processTechnical
            ),
            'Fachliche Modellierungsentscheidung erforderlich: Soll-Prozess ggf. um fehlende Pfade/Schritte ergänzen – keine automatische Änderung.',
            null
        );
    }
}
