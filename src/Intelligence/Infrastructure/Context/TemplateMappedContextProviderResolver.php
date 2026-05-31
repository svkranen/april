<?php

namespace App\Intelligence\Infrastructure\Context;

use App\Intelligence\Application\ContextProviderSelection;
use App\Intelligence\Application\ProcessTemplateProvider;
use App\Intelligence\Application\TemplateContextProviderResolver;
use App\Intelligence\Connector\Amagno\AmagnoContextProviderFactory;
use App\Intelligence\Connector\Amagno\AmagnoFieldMapFactory;
use App\Service\Amagno\ConnectionDefinition;
use App\Service\Amagno\ConnectionRegistry;
use Psr\Log\LoggerInterface;
use RuntimeException;

final readonly class TemplateMappedContextProviderResolver implements TemplateContextProviderResolver
{
    public function __construct(
        private ProcessTemplateProvider $templateProvider,
        private AmagnoFieldMapFactory $amagnoFieldMapFactory,
        private AmagnoContextProviderFactory $amagnoContextProviderFactory,
        private ?ConnectionRegistry $connectionRegistry = null,
        private ?LoggerInterface $logger = null
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
        $this->logger?->debug('Loaded field mappings', [
            'process_key' => $processKey,
            'field_map' => $fieldMap,
        ]);

        if ($template->connector !== null && strtolower($template->connector->type) !== 'amagno') {
            $this->logger?->warning(sprintf(
                'Unsupported process template connector "%s" for process "%s".',
                $template->connector->type,
                $processKey
            ));

            return null;
        }

        if ($template->connector !== null && $template->connector->connection === null) {
            $this->logger?->warning(sprintf(
                'Process template "%s" uses Amagno connector without a connection key.',
                $processKey
            ));

            return null;
        }

        $connection = $this->connectionForTemplate($processKey, $template->connector?->connection);
        if ($template->connector !== null && $connection === null) {
            return null;
        }

        if ($connection !== null) {
            return new ContextProviderSelection(
                $this->amagnoContextProviderFactory->fromFieldMapForConnection($fieldMap, $connection),
                $template->contextProfileRequiredFields,
                $template
            );
        }

        return new ContextProviderSelection(
            $this->amagnoContextProviderFactory->fromFieldMap($fieldMap),
            $template->contextProfileRequiredFields,
            $template
        );
    }

    private function connectionForTemplate(string $processKey, ?string $connectionKey): ?ConnectionDefinition
    {
        if ($connectionKey !== null) {
            if ($this->connectionRegistry === null) {
                $this->logger?->warning(sprintf(
                    'Process template "%s" requires Amagno connection "%s", but no ConnectionRegistry is available.',
                    $processKey,
                    $connectionKey
                ));

                return null;
            }

            try {
                return $this->connectionRegistry->get($connectionKey);
            } catch (RuntimeException $exception) {
                $this->logger?->warning(sprintf(
                    'Amagno connection "%s" for process template "%s" was not found: %s',
                    $connectionKey,
                    $processKey,
                    $exception->getMessage()
                ));

                return null;
            }
        }

        return $this->defaultConnection($processKey);
    }

    private function defaultConnection(string $processKey): ?ConnectionDefinition
    {
        if ($this->connectionRegistry === null) {
            return null;
        }

        try {
            return $this->connectionRegistry->get('default');
        } catch (RuntimeException) {
        }

        $connections = $this->connectionRegistry->all();
        if (count($connections) === 1) {
            return $connections[0];
        }

        if ($connections !== []) {
            $this->logger?->warning(sprintf(
                'Process template "%s" has Amagno field mapping but no connector.connection; %d connections are available.',
                $processKey,
                count($connections)
            ));
        }

        return null;
    }
}
