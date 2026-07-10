<?php

namespace App\Intelligence\Port;

use App\Intelligence\Domain\ProcessTemplate;

/** Extension point implemented by optional connector packages. */
interface ConnectorContextProviderFactory
{
    public function supports(string $connectorType, ?string $connectionName = null): bool;

    public function create(ProcessTemplate $template): ContextProvider;
}
