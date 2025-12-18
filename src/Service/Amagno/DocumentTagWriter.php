<?php

namespace App\Service\Amagno;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

class DocumentTagWriter
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger
    ) {
    }

    public function writeSingleLineTag(
        string $baseUri,
        string $token,
        string $documentId,
        string $tagDefinitionId,
        string $value
    ): void {
        $url = sprintf('%s/api/v2/documents/%s/tags', rtrim($baseUri, '/'), $documentId);

        try {
            $this->httpClient->request('PUT', $url, [
                'headers' => [
                    'Authorization' => 'Bearer '.$token,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => [
                    'singleLineStrings' => [
                        [
                            'tagDefinitionId' => $tagDefinitionId,
                            'value' => $value,
                        ],
                    ],
                ],
            ]);
        } catch (Throwable $exception) {
            $this->logger->error('Fehler beim Schreiben des Fehler-Merkmals', [
                'document' => $documentId,
                'tagDefinitionId' => $tagDefinitionId,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
