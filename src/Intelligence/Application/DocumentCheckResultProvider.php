<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessTemplate;

/**
 * Provides the on-demand Soll/Ist check result for one document as a flat view
 * model. Introduced so the UI can depend on a fakeable interface instead of the
 * final ProcessTemplateCheckService.
 */
interface DocumentCheckResultProvider
{
    public function forDocument(ProcessTemplate $template, string $documentUuid): DocumentCheckResultView;
}
