<?php

namespace App\Intelligence\Connector\Amagno;

interface AmagnoDocumentGateway
{
    /**
     * @return array<string, mixed>
     */
    public function fetchDocumentTags(string $documentId, ?string $tokenOverride = null, ?string $baseUriOverride = null, ?int $credentialIdOverride = null): array;

    /**
     * @return array<string, mixed>
     */
    public function fetchSelectionNode(string $nodeId, ?string $tokenOverride = null, ?string $baseUriOverride = null, ?int $credentialIdOverride = null): array;

    /**
     * @return array<string, mixed>
     */
    public function fetchTagDefinitions(?string $tokenOverride = null, ?string $baseUriOverride = null, ?int $credentialIdOverride = null): array;
}
