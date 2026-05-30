<?php

namespace App\Intelligence\Connector\Amagno;

use App\Service\Amagno\DocumentFetcher;

final class DocumentFetcherGateway implements AmagnoDocumentGateway
{
    public function __construct(
        private readonly DocumentFetcher $documentFetcher
    ) {
    }

    public function fetchDocumentTags(string $documentId, ?string $tokenOverride = null, ?string $baseUriOverride = null, ?int $credentialIdOverride = null): array
    {
        return $this->documentFetcher->fetchDocumentTags($documentId, $tokenOverride, $baseUriOverride, $credentialIdOverride);
    }

    public function fetchSelectionNode(string $nodeId, ?string $tokenOverride = null, ?string $baseUriOverride = null, ?int $credentialIdOverride = null): array
    {
        return $this->documentFetcher->fetchSelectionNode($nodeId, $tokenOverride, $baseUriOverride, $credentialIdOverride);
    }

    public function fetchTagDefinitions(?string $tokenOverride = null, ?string $baseUriOverride = null, ?int $credentialIdOverride = null): array
    {
        return $this->documentFetcher->fetchTagDefinitions($tokenOverride, $baseUriOverride, $credentialIdOverride);
    }
}
