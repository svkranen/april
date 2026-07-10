<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessTemplate;
use Throwable;

final class JourneyDocumentCheckService
{
    public function __construct(
        private readonly JourneyDocumentCandidateProvider $candidateProvider,
        private readonly JourneyTemplateCheckService $checkService
    ) {
    }

    public function checkDocuments(
        ProcessTemplate $template,
        ?int $documentVersion = null,
        EventTimelineOrder $order = EventTimelineOrder::DEFAULT,
        ?int $limit = null
    ): JourneyDocumentCheckReport {
        $candidates = $this->candidateProvider->candidates($template, $limit);
        $rows = [];

        foreach ($candidates->documentRefs as $documentRef) {
            try {
                $rows[] = new JourneyDocumentCheckRow(
                    $documentRef,
                    $this->checkService->check($documentRef->documentUuid, $template, $documentVersion, $order)
                );
            } catch (Throwable $exception) {
                $rows[] = new JourneyDocumentCheckRow(
                    $documentRef,
                    error: $exception->getMessage() !== '' ? $exception->getMessage() : 'Unknown journey check error.'
                );
            }
        }

        return new JourneyDocumentCheckReport(
            $template->key,
            $candidates->matchProcessKeys,
            $candidates->warnings,
            $rows
        );
    }
}
