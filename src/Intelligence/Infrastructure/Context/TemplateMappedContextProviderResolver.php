<?php

namespace App\Intelligence\Infrastructure\Context;

use App\Intelligence\Application\ContextProviderSelection;
use App\Intelligence\Application\ProcessTemplateProvider;
use App\Intelligence\Application\TemplateContextProviderResolver;
use App\Intelligence\Connector\Amagno\AmagnoContextProviderFactory;
use App\Intelligence\Connector\Amagno\AmagnoFieldMapFactory;

final readonly class TemplateMappedContextProviderResolver implements TemplateContextProviderResolver
{
    public function __construct(
        private ProcessTemplateProvider $templateProvider,
        private AmagnoFieldMapFactory $amagnoFieldMapFactory,
        private AmagnoContextProviderFactory $amagnoContextProviderFactory
    ) {
    }

    public function resolve(string $processKey): ?ContextProviderSelection
    {
        $template = $this->templateProvider->findByProcessKey($processKey);
        if ($template === null || $template->contextProfileRequiredFields === []) {
            return null;
        }

        $fieldMap = $this->amagnoFieldMapFactory->fromTemplate($template);
        if ($fieldMap === []) {
            return null;
        }

        return new ContextProviderSelection(
            $this->amagnoContextProviderFactory->fromFieldMap($fieldMap),
            $template->contextProfileRequiredFields
        );
    }
}
