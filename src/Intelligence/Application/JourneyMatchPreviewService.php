<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateMatch;

/**
 * Read-only preview of journey candidate matching for the match editor.
 *
 * Pure orchestration: candidate selection and journey checks stay in the
 * existing JourneyDocumentCheckService / JourneyDocumentCandidateProvider /
 * JourneyTemplateCheckService chain (same as the CLI command
 * intelligence:template:check-journey-documents). This service only swaps the
 * match on an in-memory template copy so different match configurations can
 * be tried without touching the YAML file.
 */
final class JourneyMatchPreviewService
{
    /** Candidate cap: every candidate triggers a full timeline check. */
    public const CANDIDATE_LIMIT = 100;

    public function __construct(
        private readonly JourneyDocumentCheckService $checkService
    ) {
    }

    /**
     * @param array<int, string>|null $overrideMatchKeys null = preview the saved template state;
     *                                                   [] = preview without explicit match (legacy fallback semantics)
     */
    public function preview(
        ProcessTemplate $template,
        ?array $overrideMatchKeys = null,
        int $limit = self::CANDIDATE_LIMIT
    ): JourneyDocumentCheckReport {
        $effectiveTemplate = $overrideMatchKeys === null
            ? $template
            : $template->withMatch(
                // An empty selection removes the explicit match entirely, matching
                // what saving it would produce (the resolver then applies its legacy fallback).
                $overrideMatchKeys === [] ? null : new ProcessTemplateMatch(array_values($overrideMatchKeys))
            );

        return $this->checkService->checkDocuments($effectiveTemplate, null, EventTimelineOrder::DEFAULT, $limit);
    }
}
