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
        private VisibilityCheckResultProvider $visibilityResultProvider,
        private GraphFindingAttribution $attribution = new GraphFindingAttribution()
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

        // Set of decision gateway node ids the graph actually draws; attribution
        // only targets these, never an invented node.
        $gatewayNodeIds = [];
        foreach ($template->decisionPoints as $decisionPoint) {
            $gatewayNodeIds[ProcessTemplateGraphFactory::gatewayNodeId($decisionPoint->key)] = true;
        }

        /** @var array<string, array{target: string, nodeId: ?string, label: string, message: string, count: int, docs: array<string, bool>}> $attributedAcc */
        $attributedAcc = [];

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

                // Attribute the structured deviations to a gateway/edge where it is
                // unambiguous; everything else (incl. the unstructured remainder)
                // stays in the process-wide bucket below.
                $attributedThisDoc = $this->attributeDeviations($check, $gatewayNodeIds, $documentUuid, $attributedAcc);

                $processDeviations += max(0, count($check->deviations) - $attributedThisDoc);
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

        [$gatewayStatusByNodeId, $attributedFindings] = $this->buildAttributed($attributedAcc);

        return new TemplateGraphFindings(
            $stepSummaries,
            $total,
            $processed,
            $total > $limit,
            $processDeviations,
            $processWarnings,
            $processTechnical,
            $gatewayStatusByNodeId,
            $attributedFindings
        );
    }

    /**
     * Classifies one document's structured deviations and folds the attributable
     * ones into $acc. Returns how many deviations were attributed (and must
     * therefore NOT be counted process-wide).
     *
     * @param array<string, bool> $gatewayNodeIds
     * @param array<string, array{target: string, nodeId: ?string, label: string, message: string, count: int, docs: array<string, bool>}> $acc
     */
    private function attributeDeviations(DocumentCheckResultView $check, array $gatewayNodeIds, string $documentUuid, array &$acc): int
    {
        $attributed = 0;
        foreach ($check->deviationDetails as $deviation) {
            $attribution = $this->attribution->attribute($deviation, $gatewayNodeIds);
            if ($attribution->isProcess()) {
                continue;
            }

            ++$attributed;
            if ($attribution->target === FindingAttribution::TARGET_GATEWAY) {
                $key = 'gateway:'.$attribution->nodeId;
                $label = $deviation->decisionKey ?? $attribution->nodeId;
            } else {
                $key = 'transition:'.$attribution->from."\0".$attribution->actual;
                $label = $attribution->from.' → '.$attribution->actual;
            }

            if (!isset($acc[$key])) {
                $acc[$key] = [
                    'target' => $attribution->target,
                    'nodeId' => $attribution->target === FindingAttribution::TARGET_GATEWAY ? $attribution->nodeId : null,
                    'label' => $label,
                    'message' => $deviation->message,
                    'count' => 0,
                    'docs' => [],
                ];
            }
            ++$acc[$key]['count'];
            $acc[$key]['docs'][$documentUuid] = true;
        }

        return $attributed;
    }

    /**
     * Turns the per-anchor accumulator into the gateway colouring map and the
     * ordered display list. All transition/decision violations map to the
     * "deviation" severity, mirroring DocumentFindingsView.
     *
     * @param array<string, array{target: string, nodeId: ?string, label: string, message: string, count: int, docs: array<string, bool>}> $acc
     * @return array{0: array<string, string>, 1: array<int, AttributedFinding>}
     */
    private function buildAttributed(array $acc): array
    {
        // Gateways first, then transitions; stable by label within each group.
        uasort($acc, static function (array $a, array $b): int {
            $order = static fn (string $target): int => $target === FindingAttribution::TARGET_GATEWAY ? 0 : 1;

            return [$order($a['target']), $a['label']] <=> [$order($b['target']), $b['label']];
        });

        $gatewayStatusByNodeId = [];
        $findings = [];
        foreach ($acc as $entry) {
            if ($entry['target'] === FindingAttribution::TARGET_GATEWAY && $entry['nodeId'] !== null) {
                $gatewayStatusByNodeId[$entry['nodeId']] = FindingSeverityFilter::DEVIATION;
            }

            $findings[] = new AttributedFinding(
                $entry['target'],
                $entry['label'],
                FindingSeverityFilter::DEVIATION,
                FindingSeverityFilter::label(FindingSeverityFilter::DEVIATION),
                $entry['message'],
                $entry['count'],
                count($entry['docs'])
            );
        }

        return [$gatewayStatusByNodeId, $findings];
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
