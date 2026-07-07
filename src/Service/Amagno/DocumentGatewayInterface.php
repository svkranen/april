<?php

namespace App\Service\Amagno;

interface DocumentGatewayInterface
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchDocuments(
        string $magnetId,
        int $limit = 50,
        ?\DateTimeInterface $modifiedSince = null,
        ?string $tokenOverride = null,
        ?string $baseUriOverride = null,
        ?int $credentialIdOverride = null,
        int $offset = 0
    ): array;

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
