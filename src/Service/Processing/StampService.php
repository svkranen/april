<?php

namespace App\Service\Processing;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class StampService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $documents
     */
    public function apply(
        array $documents,
        string $baseUri,
        string $token,
        string $stampId,
        int $maxRetries = 3,
        int $sleepSeconds = 2
    ): void
    {
        foreach ($documents as $document) {
            if (!isset($document['id'])) {
                continue;
            }

            $attempt = 0;
            $success = false;
            while ($attempt < $maxRetries && !$success) {
                $attempt++;
                $success = $this->stampDocument($baseUri, $token, $document['id'], $stampId);
                if (!$success && $attempt < $maxRetries) {
                    sleep($sleepSeconds);
                }
            }
        }
    }

    private function stampDocument(string $baseUri, string $token, string $documentId, string $stampId): bool
    {
        $url = sprintf('%s/api/v2/documents/%s/stamp', rtrim($baseUri, '/'), $documentId);
        $response = $this->httpClient->request('PUT', $url, [
            'headers' => [
                'Authorization' => 'Bearer '.$token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'json' => ['stampId' => $stampId],
        ]);

        $status = $response->getStatusCode();

        return $status >= 200 && $status < 300;
    }
}
