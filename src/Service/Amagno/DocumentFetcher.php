<?php

namespace App\Service\Amagno;

use Iileven\AmagnoConnector\Interface\TokenProviderInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class DocumentFetcher
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUri,
        private readonly ?string $apiToken = null,
        private readonly ?TokenProviderInterface $tokenProvider = null,
        private readonly ?int $credentialId = null
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchDocuments(
        string $magnetId,
        int $limit = 50,
        ?\DateTimeInterface $modifiedSince = null,
        ?string $tokenOverride = null,
        ?string $baseUriOverride = null,
        ?int $credentialIdOverride = null
    ): array {
        $query = [
            'count' => max(1, min($limit, 500)),
        ];

        if ($modifiedSince !== null) {
            $query['modifiedSince'] = $modifiedSince->format(\DateTimeInterface::ATOM);
        }

        $url = sprintf(
            '%s/magnets/%s/documents?%s',
            $this->resolveApiBaseUri($baseUriOverride),
            $magnetId,
            http_build_query($query)
        );

        $response = $this->httpClient->request('GET', $url, [
            'headers' => $this->buildHeaders($tokenOverride, $credentialIdOverride),
        ]);

        $data = $response->toArray(false);

        if (isset($data['items']) && is_array($data['items'])) {
            return $data['items'];
        }

        return is_array($data) ? $data : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchDocumentTags(string $documentId, ?string $tokenOverride = null, ?string $baseUriOverride = null, ?int $credentialIdOverride = null): array
    {
        $url = sprintf(
            '%s/documents/%s/tags',
            $this->resolveApiBaseUri($baseUriOverride),
            $documentId
        );

        $response = $this->httpClient->request('GET', $url, [
            'headers' => $this->buildHeaders($tokenOverride, $credentialIdOverride),
        ]);

        $data = $response->toArray(false);

        return is_array($data) ? $data : [];
    }

    public function fetchSelectionNode(string $nodeId, ?string $tokenOverride = null, ?string $baseUriOverride = null, ?int $credentialIdOverride = null): array
    {
        $url = sprintf(
            '%s/selection-definition-nodes/%s',
            $this->resolveApiBaseUri($baseUriOverride),
            $nodeId
        );

        $response = $this->httpClient->request('GET', $url, [
            'headers' => $this->buildHeaders($tokenOverride, $credentialIdOverride),
        ]);

        $data = $response->toArray(false);

        return is_array($data) ? $data : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchTagDefinitions(?string $tokenOverride = null, ?string $baseUriOverride = null, ?int $credentialIdOverride = null): array
    {
        $url = sprintf('%s/documents/tag-definitions', $this->resolveApiBaseUri($baseUriOverride));

        $response = $this->httpClient->request('GET', $url, [
            'headers' => $this->buildHeaders($tokenOverride, $credentialIdOverride),
        ]);

        $data = $response->toArray(false);

        return is_array($data) ? $data : [];
    }

    /**
     * @return array<string, string>
     */
    private function buildHeaders(?string $tokenOverride, ?int $credentialIdOverride = null): array
    {
        $token = $this->resolveToken($tokenOverride, $credentialIdOverride);

        return [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ];
    }

    private function resolveToken(?string $tokenOverride, ?int $credentialIdOverride = null): string
    {
        $token = $tokenOverride ?: $this->apiToken;
        if ($token !== null && $token !== '') {
            return $token;
        }

        $credentialId = $credentialIdOverride ?? $this->credentialId;
        if ($this->tokenProvider !== null && $credentialId !== null) {
            return $this->tokenProvider->getToken($credentialId)->getTokenString();
        }

        if ($token === null || $token === '') {
            throw new \RuntimeException('Kein Amagno API Token verfügbar. Setze AMAGNO_API_TOKEN oder AMAGNO_CREDENTIAL_ID.');
        }

        return $token;
    }

    private function resolveBaseUri(?string $override): string
    {
        $base = $override ?: $this->baseUri;
        if ($base === '') {
            throw new \RuntimeException('Es wurde keine Amagno Base URI konfiguriert.');
        }

        return $base;
    }

    private function resolveApiBaseUri(?string $override): string
    {
        $base = rtrim($this->resolveBaseUri($override), '/');
        if (!str_ends_with($base, '/api/v2')) {
            $base .= '/api/v2';
        }

        return $base;
    }
}
