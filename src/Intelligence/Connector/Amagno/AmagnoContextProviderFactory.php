<?php

namespace App\Intelligence\Connector\Amagno;

final readonly class AmagnoContextProviderFactory
{
    public function __construct(
        private AmagnoDocumentGateway $documentGateway,
        private AmagnoTagValueResolver $tagValueResolver,
        private ?string $token = null,
        private ?string $baseUri = null
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
            $fieldMap,
            $this->token,
            $this->baseUri
        );
    }
}
