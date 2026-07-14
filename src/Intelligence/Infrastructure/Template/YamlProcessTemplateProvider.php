<?php

namespace App\Intelligence\Infrastructure\Template;

use App\Intelligence\Application\ProcessTemplateProvider;
use App\Intelligence\Application\ProcessTemplateCatalog;
use App\Intelligence\Domain\ProcessTemplate;

final readonly class YamlProcessTemplateProvider implements ProcessTemplateProvider
{
    public function __construct(
        private ProcessTemplateCatalog $catalog
    ) {
    }

    public function findByProcessKey(string $processKey): ?ProcessTemplate
    {
        return $this->catalog->findByProcessKey($processKey);
    }
}
