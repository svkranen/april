<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessTemplate;

final readonly class ProcessTemplateDocumentationFactory
{
    public function __construct(
        private ProcessTemplateCatalog $catalog,
        private ProcessTemplateDocumentationBuilder $builder
    ) {
    }

    public function create(ProcessTemplate $template): ProcessTemplateDocumentation
    {
        return $this->builder->build(
            $template,
            $this->catalog->pathForProcessKey($template->key) ?? ''
        );
    }
}
