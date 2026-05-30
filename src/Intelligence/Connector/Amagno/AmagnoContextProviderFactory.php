<?php

namespace App\Intelligence\Connector\Amagno;

use App\Service\Amagno\ConnectionDefinition;
use Psr\Log\LoggerInterface;

final readonly class AmagnoContextProviderFactory
{
    public function __construct(
        private AmagnoDocumentGateway $documentGateway,
        private AmagnoTagValueResolver $tagValueResolver,
        private AmagnoTagDefinitionResolver $tagDefinitionResolver,
        private ?string $token = null,
        private ?string $baseUri = null,
        private ?LoggerInterface $logger = null
    ) {
    }

    /**
     * @param array<string, string> $fieldMap
     */
    public function fromFieldMap(array $fieldMap): AmagnoContextProvider
    {
        return new AmagnoContextProvider(
            $this->documentGateway,
            $this->tagValueResolver,
            $this->tagDefinitionResolver,
            $fieldMap,
            $this->token,
            $this->baseUri,
            null,
            $this->logger
        );
    }

    /**
     * @param array<string, string> $fieldMap
     */
    public function fromFieldMapForConnection(array $fieldMap, ConnectionDefinition $connection): AmagnoContextProvider
    {
        return new AmagnoContextProvider(
            $this->documentGateway,
            $this->tagValueResolver,
            $this->tagDefinitionResolver,
            $fieldMap,
            null,
            $this->apiBaseUri($connection->baseUri()),
            $connection->credentialId(),
            $this->logger
        );
    }

    private function apiBaseUri(string $baseUri): string
    {
        $baseUri = rtrim($baseUri, '/');
        if (!str_ends_with($baseUri, '/api/v2')) {
            $baseUri .= '/api/v2';
        }

        return $baseUri;
    }
}
