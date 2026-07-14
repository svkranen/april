<?php

namespace App\Intelligence\Application;

interface ProcessTemplateDocumentationRendererInterface
{
    public function render(ProcessTemplateDocumentation $documentation): string;
}
