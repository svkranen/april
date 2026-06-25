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
        private VisibilityCheckResultProvider $visibilityResultProvider
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

        foreach ($documentUuids as $documentUuid) {
            if ($computed >= $limit) {
                break;
            }
            $computed++;

            try {
                $check = $this->checkResultProvider->forDocument($template, $documentUuid);
                $records = $this->visibilityResultProvider->findByDocument($documentUuid, $template->key);
                $result[$documentUuid] = DocumentListFindingView::fromFindings(
                    $documentUuid,
                    DocumentFindingsView::fromData($check, $records)
                );
            } catch (Throwable $exception) {
                $result[$documentUuid] = DocumentListFindingView::failed($documentUuid, $exception->getMessage());
            }
        }

        return $result;
    }
}
