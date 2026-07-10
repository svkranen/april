<?php

namespace App\Intelligence\Infrastructure\Context;

use App\Intelligence\Application\ContextProviderSelection;
use App\Intelligence\Application\ConnectorContextProviderFactoryRegistry;
use App\Intelligence\Application\ProcessTemplateProvider;
use App\Intelligence\Application\TemplateContextProviderResolver;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateFieldMapping;
use Psr\Log\LoggerInterface;

final readonly class TemplateMappedContextProviderResolver implements TemplateContextProviderResolver
{
    public function __construct(
        private ProcessTemplateProvider $templateProvider,
        private ConnectorContextProviderFactoryRegistry $factoryRegistry,
        private ?LoggerInterface $logger = null
    ) {
    }

    public function resolve(string $processKey): ?ContextProviderSelection
    {
        $template = $this->templateProvider->findByProcessKey($processKey);
        if ($template === null || $template->contextProfileRequiredFields === []) {
            return null;
        }

        $connectorType = $this->connectorType($template);
        if ($connectorType === null) {
            if ($this->usesInlineEventContext($template)) {
                return new ContextProviderSelection(
                    new NullContextProvider(),
                    $template->contextProfileRequiredFields,
                    $template
                );
            }

            return null;
        }

        $provider = $this->factoryRegistry->create($template, $connectorType);
        if ($provider === null) {
            $warning = sprintf(
                'Context connector "%s" for process template "%s" is not installed or supported.',
                $connectorType,
                $processKey
            );
            $this->logger?->warning($warning);

            return $this->unavailable($template, $warning);
        }

        return new ContextProviderSelection($provider, $template->contextProfileRequiredFields, $template);
    }

    private function unavailable(ProcessTemplate $template, string $warning): ContextProviderSelection
    {
        return new ContextProviderSelection(
            new UnavailableContextProvider($warning),
            $template->contextProfileRequiredFields,
            $template
        );
    }

    private function usesInlineEventContext(ProcessTemplate $template): bool
    {
        foreach ($template->fieldMappings as $mapping) {
            if ($mapping instanceof ProcessTemplateFieldMapping && strtolower($mapping->source) === 'event_context') {
                return true;
            }
        }

        return false;
    }

    private function connectorType(ProcessTemplate $template): ?string
    {
        if ($template->connector !== null && trim($template->connector->type) !== '') {
            return $template->connector->type;
        }

        $sources = [];
        foreach ($template->fieldMappings as $mapping) {
            if (!$mapping instanceof ProcessTemplateFieldMapping) {
                continue;
            }
            $source = strtolower(trim($mapping->source));
            if ($source !== '' && $source !== 'event_context') {
                $sources[$source] = true;
            }
        }

        return count($sources) === 1 ? array_key_first($sources) : null;
    }
}
