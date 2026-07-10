<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Port\ConnectorContextProviderFactory;
use App\Intelligence\Port\ContextProvider;

final readonly class ConnectorContextProviderFactoryRegistry
{
    /** @param iterable<ConnectorContextProviderFactory> $factories */
    public function __construct(private iterable $factories = [])
    {
    }

    public function create(ProcessTemplate $template, string $connectorType): ?ContextProvider
    {
        if (trim($connectorType) === '') {
            return null;
        }

        foreach ($this->factories as $factory) {
            if ($factory->supports($connectorType, $template->connector?->connection)) {
                return $factory->create($template);
            }
        }

        return null;
    }
}
