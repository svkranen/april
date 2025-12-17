<?php

namespace App\Service\Amagno;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class DocumentFetcher
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUri,
        private readonly string $apiToken
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchDocuments(
        string $magnetId,
        int $limit = 50,
        ?\DateTimeInterface $modifiedSince = null,
        ?string $tokenOverride = null
    ): array {
        $query = [
            'count' => max(1, min($limit, 500)),
        ];

        if ($modifiedSince !== null) {
            $query['modifiedSince'] = $modifiedSince->format(\DateTimeInterface::ATOM);
        }

        $url = sprintf(
            '%s/api/v2/magnets/%s/documents?%s',
            rtrim($this->baseUri, '/'),
            $magnetId,
            http_build_query($query)
        );

        $response = $this->httpClient->request('GET', $url, [
            'headers' => $this->buildHeaders($tokenOverride),
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
    public function fetchDocumentTags(string $documentId, ?string $tokenOverride = null): array
    {
        $url = sprintf(
            '%s/api/v2/documents/%s/tags',
            rtrim($this->baseUri, '/'),
            $documentId
        );

        $response = $this->httpClient->request('GET', $url, [
            'headers' => $this->buildHeaders($tokenOverride),
        ]);

        $data = $response->toArray(false);

        return is_array($data) ? $data : [];
    }

    public function fetchSelectionNode(string $nodeId, ?string $tokenOverride = null): array
    {
        $url = sprintf(
            '%s/api/v2/selection-definition-nodes/%s',
            rtrim($this->baseUri, '/'),
            $nodeId
        );

        $response = $this->httpClient->request('GET', $url, [
            'headers' => $this->buildHeaders($tokenOverride),
        ]);

        $data = $response->toArray(false);

        return is_array($data) ? $data : [];
    }

    /**
     * @return array<string, string>
     */
    private function buildHeaders(?string $tokenOverride): array
    {
        return [
            'Authorization' => 'Bearer '.($tokenOverride ?: $this->apiToken),
            'Accept' => 'application/json',
        ];
    }
}
