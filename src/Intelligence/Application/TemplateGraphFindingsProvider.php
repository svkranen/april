<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessTemplate;
use Throwable;

/**
 * Computes per-step findings for the template Mermaid graph on demand. Reuses the
 * existing (cheap) check provider and stored visibility results - no new
 * persistence and no access-probe execution.
 *
 * Step-attributable findings come from the stored visibility results (they carry
 * a stepKey); the status -> severity mapping mirrors DocumentFindingsView so the
 * graph stays consistent with the document detail/list. Soll/Ist process
 * deviations are document-level and aggregated separately, never onto a step.
 *
 * Computation is bounded by a caller-provided limit and a failure on a single
 * document degrades to one "technical" process finding instead of failing the
 * whole page.
 */
final readonly class TemplateGraphFindingsProvider
{
    public function __construct(
        private DocumentCheckResultProvider $checkResultProvider,
        private VisibilityCheckResultProvider $visibilityResultProvider
    ) {
    }

    /**
     * @param array<int, string> $documentUuids
     */
    public function aggregate(ProcessTemplate $template, array $documentUuids, int $limit): TemplateGraphFindings
    {
        $total = count($documentUuids);
        $processed = 0;
        $processDeviations = 0;
        $processWarnings = 0;
        $processTechnical = 0;

        /** @var array<string, array<int, string>> $severitiesByStep */
        $severitiesByStep = [];
        foreach ($template->steps as $step) {
            $severitiesByStep[$step->key] = [];
        }

        foreach ($documentUuids as $documentUuid) {
            if ($processed >= $limit) {
                break;
            }
            ++$processed;

            try {
                $records = $this->visibilityResultProvider->findByDocument($documentUuid, $template->key);
                foreach ($records as $record) {
                    $severity = self::severityForVisibilityStatus($record->status);
                    if ($severity === null) {
                        continue;
                    }
                    // Only count findings for steps the template actually declares.
                    if (!array_key_exists($record->stepKey, $severitiesByStep)) {
                        continue;
                    }
                    $severitiesByStep[$record->stepKey][] = $severity;
                }

                $check = $this->checkResultProvider->forDocument($template, $documentUuid);
                if (!$check->available) {
                    ++$processTechnical;
                    continue;
                }
                $processDeviations += count($check->deviations);
                $processWarnings += count($check->warnings);
                if ($check->deviations === [] && str_contains($check->status, 'DEVIATION')) {
                    ++$processDeviations;
                }
            } catch (Throwable) {
                // Defensive: a single broken document must not break the graph page.
                ++$processTechnical;
            }
        }

        $stepSummaries = [];
        foreach ($template->steps as $step) {
            $stepSummaries[$step->key] = StepFindingSummary::fromSeverities(
                $step->key,
                $severitiesByStep[$step->key],
                true
            );
        }

        return new TemplateGraphFindings(
            $stepSummaries,
            $total,
            $processed,
            $total > $limit,
            $processDeviations,
            $processWarnings,
            $processTechnical
        );
    }

    /**
     * Mirrors the visibility branch of DocumentFindingsView::fromData. Returns the
     * severity for a step-attributable visibility status, or null for statuses
     * that produce no finding (ok and anything else).
     */
    private static function severityForVisibilityStatus(string $status): ?string
    {
        return match ($status) {
            'violation' => FindingSeverityFilter::CRITICAL,
            'warning' => FindingSeverityFilter::WARNING,
            'technical_warning', 'unknown', 'skipped' => FindingSeverityFilter::TECHNICAL,
            default => null,
        };
    }
}
