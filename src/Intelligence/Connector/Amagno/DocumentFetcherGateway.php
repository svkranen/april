<?php

namespace App\Intelligence\Connector\Amagno;

use App\Service\Amagno\DocumentFetcher;

final class DocumentFetcherGateway implements AmagnoDocumentGateway
{
    public function __construct(
        private readonly DocumentFetcher $documentFetcher
    ) {
    }

    public function fetchDocuments(string $magnetId, int $limit = 50, int $offset = 0): array
    {
        return $this->documentFetcher->fetchDocuments($magnetId, $limit, offset: $offset);
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
