<?php

namespace App\Intelligence\Infrastructure\Access;

use App\Intelligence\Application\AccessProbeResult;
use App\Intelligence\Connector\Amagno\AmagnoDocumentGateway;
use App\Intelligence\Domain\ProcessTemplateAccessProbe;
use App\Intelligence\Port\AccessProbeProvider;
use Throwable;

final readonly class AmagnoMagnetDocumentsAccessProbeProvider implements AccessProbeProvider
{
    public function __construct(
        private AmagnoDocumentGateway $documentGateway
    ) {
    }

    public function supports(string $sourceSystem, string $type): bool
    {
        return $sourceSystem === 'amagno' && $type === 'amagno_magnet_documents';
    }

    public function evaluate(ProcessTemplateAccessProbe $probe, string $documentUuid): AccessProbeResult
    {
        $magnetId = $this->magnetId($probe);
        if ($magnetId === null) {
            return AccessProbeResult::skipped('missing_magnet_id');
        }

        $scanLimit = $this->scanLimit($probe);
        if ($scanLimit === null) {
            return AccessProbeResult::skipped('invalid_max_documents');
        }

        $pageSize = $this->pageSize($probe, $scanLimit);
        $offset = 0;
        $scannedDocuments = 0;

        while (true) {
            try {
                $documents = $this->documentGateway->fetchDocuments($magnetId, $pageSize, $offset);
            } catch (Throwable $exception) {
                return AccessProbeResult::unknown('api_error', $scannedDocuments === 0 ? null : $scannedDocuments, [
                    'message' => $exception->getMessage(),
                    'magnetId' => $magnetId,
                    'offset' => $offset,
                    'pageSize' => $pageSize,
                ]);
            }

            $pageDocumentCount = count($documents);
            $scannedDocuments += $pageDocumentCount;

            foreach ($documents as $document) {
                if ($this->documentMatchesUuid($document, $documentUuid)) {
                    return AccessProbeResult::visible($scannedDocuments, [
                        'magnetId' => $magnetId,
                        'offset' => $offset,
                        'pageSize' => $pageSize,
                    ]);
                }
            }

            if ($pageDocumentCount < $pageSize) {
                return AccessProbeResult::hidden($scannedDocuments, [
                    'magnetId' => $magnetId,
                    'pageSize' => $pageSize,
                ]);
            }

            if ($scannedDocuments >= $scanLimit) {
                return AccessProbeResult::skipped('probe_scan_limit_reached', $scannedDocuments, [
                    'magnetId' => $magnetId,
                    'pageSize' => $pageSize,
                    'maxDocuments' => $scanLimit,
                ]);
            }

            $offset += $pageSize;
        }
    }

    /**
     * @param array<string, mixed> $document
     */
    public function documentMatchesUuid(array $document, string $documentUuid): bool
    {
        foreach (['documentUuid', 'uuid'] as $field) {
            if (($document[$field] ?? null) === $documentUuid) {
                return true;
            }
        }

        foreach (['id', 'documentId'] as $field) {
            $value = $document[$field] ?? null;
            if (!is_string($value) || $value !== $documentUuid) {
                continue;
            }

            return $this->looksLikeUuid($documentUuid);
        }

        return false;
    }

    private function magnetId(ProcessTemplateAccessProbe $probe): ?string
    {
        $value = $probe->options['magnet_id'] ?? $probe->options['magnetId'] ?? null;
        if (!is_scalar($value)) {
            return null;
        }

        $magnetId = trim((string) $value);

        return $magnetId === '' ? null : $magnetId;
    }

    private function pageSize(ProcessTemplateAccessProbe $probe, int $scanLimit): int
    {
        $value = $probe->options['page_size'] ?? $probe->options['pageSize'] ?? null;
        $configuredPageSize = is_numeric($value) ? (int) $value : 50;

        return max(1, min($configuredPageSize, 500, $scanLimit));
    }

    private function scanLimit(ProcessTemplateAccessProbe $probe): ?int
    {
        if ($probe->maxDocuments === null) {
            return 500;
        }

        if ($probe->maxDocuments <= 0) {
            return null;
        }

        return $probe->maxDocuments;
    }

    private function looksLikeUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }
}
