<?php

namespace App\Service\Export;

use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AmagnoUploader
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUri,
        private readonly LoggerInterface $logger
    ) {
    }

    public function upload(string $token, string $vaultId, string $filename, string $path): void
    {
        $this->logger->info('Starte Amagno Upload', ['vault' => $vaultId, 'filename' => $filename]);
        $documentId = $this->createDocument($token, $vaultId, $filename, filesize($path));
        $this->uploadFile($token, $documentId, $filename, $path);
    }

    private function createDocument(string $token, string $vaultId, string $filename, int $size): string
    {
        $url = sprintf('%s/api/v2/vaults/%s/checked-out-documents', rtrim($this->baseUri, '/'), $vaultId);
        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.000\Z');
        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Authorization' => 'Bearer '.$token,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => [
                    'metadata' => [
                        'createDate' => $now,
                        'changeDate' => $now,
                        'name' => $filename,
                        'size' => $size,
                    ],
                    'generateNonExistingNameIfNameExists' => true,
                ],
            ]);
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('Fehler beim Anlegen des Dokuments', ['error' => $exception->getMessage()]);
            throw $exception;
        }

        $content = $response->getContent(false);
        $data = json_decode($content, true);
        $documentId = $data['document']['id'] ?? $data['id'] ?? null;

        if ($documentId === null) {
            foreach ($response->getHeaders(false) as $name => $values) {
                if (strtolower($name) === 'location' && isset($values[0])) {
                    if (preg_match('/documents\/([0-9a-fA-F-]+)/', $values[0], $match)) {
                        $documentId = $match[1];
                        break;
                    }
                }
            }
        }

        if ($documentId === null) {
            if (preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', $content, $match)) {
                $documentId = $match[0];
            }
        }

        if ($documentId === null) {
            $this->logger->error('Dokument-ID konnte nicht ermittelt werden');
            throw new RuntimeException('Konnte Dokument-ID nach dem Upload nicht bestimmen.');
        }

        return $documentId;
    }

    private function uploadFile(string $token, string $documentId, string $filename, string $path): void
    {
        $url = sprintf('%s/api/v2/documents/%s/file', rtrim($this->baseUri, '/'), $documentId);
        $this->logger->info('Lade Dateiinhalt hoch', ['documentId' => $documentId]);
        try {
            $this->httpClient->request('PUT', $url, [
                'headers' => [
                    'Authorization' => 'Bearer '.$token,
                ],
                'body' => [
                    'file' => fopen($path, 'rb'),
                ],
            ]);
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('Fehler beim Upload des Dateiinhalts', ['documentId' => $documentId, 'error' => $exception->getMessage()]);
            throw $exception;
        }
    }
}
