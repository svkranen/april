<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessTemplateSuggestionResult;

final class TemplateSuggestionService
{
    private readonly TemplateSuggestionScopeResolver $scopeResolver;

    public function __construct(
        private readonly ProcessTemplateSuggestionService $processSuggestionService,
        private readonly JourneyTemplateSuggestionService $journeySuggestionService,
        private readonly ?ProcessTemplateProvider $templateProvider = null,
        ?TemplateSuggestionScopeResolver $scopeResolver = null
    ) {
        $this->scopeResolver = $scopeResolver ?? new TemplateSuggestionScopeResolver();
    }

    public function suggestFromDocument(
        string $documentUuid,
        string $templateKey,
        ?int $documentVersion = null,
        bool $includeBefore = false,
        EventTimelineOrder $order = EventTimelineOrder::DEFAULT,
        ?string $scopeOverride = null
    ): ?ProcessTemplateSuggestionResult {
        $targetTemplate = $this->templateProvider?->findByProcessKey($templateKey);
        $scope = $this->scopeResolver->resolve($targetTemplate, $scopeOverride);

        if ($scope === TemplateSuggestionScopeResolver::SCOPE_JOURNEY) {
            return $this->journeySuggestionService->suggest(
                $documentUuid,
                $templateKey,
                $targetTemplate,
                $documentVersion,
                $includeBefore,
                $order
            );
        }

        return $this->processSuggestionService->suggest(
            $documentUuid,
            $templateKey,
            $documentVersion,
            $includeBefore,
            $order
        );
    }
}
