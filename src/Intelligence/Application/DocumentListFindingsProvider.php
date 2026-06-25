<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessTemplate;
use Throwable;

/**
 * Computes per-document findings for the document list on demand. Reuses the
 * existing (cheap, timeline-based) check provider, the stored visibility results
 * and DocumentFindingsView - no new persistence and no access-probe execution.
 *
 * Computation is bounded by a caller-provided limit so an unpaginated list never
 * triggers an unbounded number of per-document reads. A failure on a single
 * document degrades to a "technical" row instead of failing the whole list.
 */
final readonly class DocumentListFindingsProvider
{
    public function __construct(
        private DocumentCheckResultProvider $checkResultProvider,
        private VisibilityCheckResultProvider $visibilityResultProvider,
        private GraphFindingAttribution $attribution = new GraphFindingAttribution()
    ) {
    }

    /**
     * @param array<int, string> $documentUuids
     * @return array<string, DocumentListFindingView> keyed by document UUID (only the first $limit)
     */
    public function forDocuments(ProcessTemplate $template, array $documentUuids, int $limit): array
    {
        $result = [];
        $computed = 0;

        // Decision gateways the graph draws; transition/decision attribution targets
        // only these, identical to the graph page (no free-text parsing).
        $gatewayNodeIds = [];
        foreach ($template->decisionPoints as $decisionPoint) {
            $gatewayNodeIds[ProcessTemplateGraphFactory::gatewayNodeId($decisionPoint->key)] = true;
        }

        foreach ($documentUuids as $documentUuid) {
            if ($computed >= $limit) {
                break;
            }
            $computed++;

            try {
                $check = $this->checkResultProvider->forDocument($template, $documentUuid);
                $records = $this->visibilityResultProvider->findByDocument($documentUuid, $template->key);
                [$decisionKeys, $transitionKeys] = $this->attributedKeys($check, $gatewayNodeIds);
                $result[$documentUuid] = DocumentListFindingView::fromFindings(
                    $documentUuid,
                    DocumentFindingsView::fromData($check, $records),
                    $decisionKeys,
                    $transitionKeys
                );
            } catch (Throwable $exception) {
                $result[$documentUuid] = DocumentListFindingView::failed($documentUuid, $exception->getMessage());
            }
        }

        return $result;
    }

    /**
     * Distinct decision keys and transition keys attributable to this document,
     * derived from the structured deviation details only.
     *
     * @param array<string, bool> $gatewayNodeIds
     * @return array{0: array<int, string>, 1: array<int, string>} [decisionKeys, transitionKeys]
     */
    private function attributedKeys(DocumentCheckResultView $check, array $gatewayNodeIds): array
    {
        $decisionKeys = [];
        $transitionKeys = [];
        foreach ($check->deviationDetails as $deviation) {
            $attribution = $this->attribution->attribute($deviation, $gatewayNodeIds);

            if ($attribution->target === FindingAttribution::TARGET_GATEWAY && $deviation->decisionKey !== null) {
                if (!in_array($deviation->decisionKey, $decisionKeys, true)) {
                    $decisionKeys[] = $deviation->decisionKey;
                }
            } elseif ($attribution->target === FindingAttribution::TARGET_TRANSITION) {
                $key = DocumentListFindingView::transitionKey($attribution->from, $attribution->actual);
                if (!in_array($key, $transitionKeys, true)) {
                    $transitionKeys[] = $key;
                }
            }
        }

        return [$decisionKeys, $transitionKeys];
    }
}
