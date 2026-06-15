<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessTemplate;
use Throwable;

/**
 * Default DocumentCheckResultProvider: wraps the (final) ProcessTemplateCheckService.
 *
 * The check works purely on stored timeline/context data (no Amagno/HTTP I/O),
 * so it is computed on demand. Any failure (e.g. invalid template metadata) is
 * turned into an unavailable view so the page stays 200 instead of erroring.
 */
final readonly class ProcessTemplateCheckResultProvider implements DocumentCheckResultProvider
{
    public function __construct(
        private ProcessTemplateCheckService $checkService
    ) {
    }

    public function forDocument(ProcessTemplate $template, string $documentUuid): DocumentCheckResultView
    {
        try {
            $result = $this->checkService->checkDocument($documentUuid, $template->key, $template);
        } catch (Throwable $exception) {
            return DocumentCheckResultView::unavailable($exception->getMessage());
        }

        return DocumentCheckResultView::fromResult($result);
    }
}
