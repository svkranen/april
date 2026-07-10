<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessTemplate;

final class JourneyDocumentCandidateProvider
{
    private readonly JourneyTemplateMatchResolver $matchResolver;

    public function __construct(
        private readonly ProcessDocumentUuidProvider $documentUuidProvider,
        ?JourneyTemplateMatchResolver $matchResolver = null
    ) {
        $this->matchResolver = $matchResolver ?? new JourneyTemplateMatchResolver();
    }

    public function candidates(ProcessTemplate $template, ?int $limit = null): JourneyDocumentCandidateResult
    {
        $match = $this->matchResolver->resolve($template);
        if (!$match->isMatchable()) {
            return new JourneyDocumentCandidateResult([], [], $match->warnings);
        }

        $refsByUuid = [];
        foreach ($match->processKeys as $processKey) {
            foreach ($this->documentUuidProvider->documentRefsForProcess($processKey) as $documentRef) {
                $refsByUuid[$documentRef->documentUuid] ??= $documentRef;
            }
        }

        $documentRefs = array_values($refsByUuid);
        if ($limit !== null) {
            $documentRefs = array_slice($documentRefs, 0, max(0, $limit));
        }

        return new JourneyDocumentCandidateResult($match->processKeys, $documentRefs, $match->warnings);
    }
}
