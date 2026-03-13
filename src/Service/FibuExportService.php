<?php

namespace App\Service;

use App\Dto\SyncOptions;
use App\Service\Amagno\CredentialStore;
use App\Service\Amagno\DocumentFetcher;
use App\Service\Amagno\DocumentTagWriter;
use App\Service\Checkpoint\CheckpointStore;
use App\Service\Export\ExporterRegistry;
use App\Service\Processing\DocumentMatrixBuilder;
use App\Service\Processing\MatchingProvider;
use App\Service\Processing\StampService;
use App\Service\Processing\TemplateRenderer;
use DateTimeImmutable;
use Iileven\AmagnoConnector\Interface\TokenProviderInterface;
use RuntimeException;

class FibuExportService
{
    public function __construct(
        private readonly ?string $defaultBaseUri,
        private readonly ?int $defaultCredentialId,
        private readonly ?string $defaultApiToken,
        private readonly ?string $defaultApiUsername,
        private readonly ?string $defaultApiPassword,
        private readonly ?string $defaultApiAuthType,
        private readonly DocumentFetcher $documentFetcher,
        private readonly MatchingProvider $matchingProvider,
        private readonly DocumentMatrixBuilder $matrixBuilder,
        private readonly TemplateRenderer $templateRenderer,
        private readonly ExporterRegistry $exporterRegistry,
        private readonly StampService $stampService,
        private readonly CheckpointStore $checkpointStore,
        private readonly TokenProviderInterface $tokenProvider,
        private readonly CredentialStore $credentialStore,
        private readonly DocumentTagWriter $tagWriter
    ) {
    }

    public function sync(SyncOptions $options): array
    {
        $checkpointApplied = null;
        if ($options->useCheckpoint && $options->modifiedSince === null) {
            $checkpointData = $this->checkpointStore->read($options->checkpointKey());
            if ($checkpointData !== null && isset($checkpointData['last_change'])) {
                $options->modifiedSince = new DateTimeImmutable($checkpointData['last_change']);
                $checkpointApplied = $options->modifiedSince;
            }
        }

        $payload = $this->buildPayload($options);

        try {
            $result = $this->runExport($payload, $options);
        } catch (\Throwable $exception) {
            $this->handleError($options, $exception);
            throw $exception;
        }

        if ($checkpointApplied !== null) {
            $result['checkpoint_from'] = $checkpointApplied->format(DateTimeImmutable::ATOM);
        }

        if ($options->useCheckpoint && !$options->dryRun) {
            $latestChange = $this->determineLatestChange($result['documents'] ?? []);
            if ($latestChange !== null) {
                $this->checkpointStore->write($options->checkpointKey(), [
                    'last_run' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
                    'last_change' => $latestChange,
                ]);
                $result['checkpoint_updated'] = $latestChange;
            }
        }

        return $result;
    }

    private function runExport(array $payload, SyncOptions $options): array
    {
        $matchingContext = $this->matchingProvider->resolve(
            $payload['profile'] ?? null,
            $payload['template'] ?? null
        );

        $token = $payload['atoken'];
        $baseUri = $payload['base_uri'] ?? ($options->baseUri ?? $this->defaultBaseUri);
        if ($baseUri === null || $baseUri === '') {
            throw new RuntimeException('Es wurde keine Amagno Base URI angegeben.');
        }

        $tagCache = [];
        $selectionCache = [];
        $debugInfo = [];

        $documents = $this->documentFetcher->fetchDocuments(
            $payload['magnetid'],
            $options->batchSize,
            $options->modifiedSince,
            $token,
            $baseUri
        );
        $documents = $this->deduplicateDocuments($documents, $debugInfo);

        $tagFetcher = function (string $documentId) use (&$tagCache, $token, $baseUri) {
            if (!array_key_exists($documentId, $tagCache)) {
                $tagCache[$documentId] = $this->documentFetcher->fetchDocumentTags($documentId, $token, $baseUri);
            }

            return $tagCache[$documentId];
        };

        $selectionFetcher = function (string $nodeId) use (&$selectionCache, $token, $baseUri) {
            if (!array_key_exists($nodeId, $selectionCache)) {
                $selectionCache[$nodeId] = $this->documentFetcher->fetchSelectionNode($nodeId, $token, $baseUri);
            }

            return $selectionCache[$nodeId];
        };

        $matrix = $this->matrixBuilder->build(
            $documents,
            $tagFetcher,
            $selectionFetcher,
            $matchingContext->matching
        );

        $debug = $debugInfo;
        $rendered = $this->templateRenderer->render(
            $matrix,
            $matchingContext->matching,
            $matchingContext->templateContent,
            $selectionFetcher,
            $documents,
            $debug
        );

        if (!$options->dryRun) {
            $this->exporterRegistry->export(
                $payload['export'],
                $rendered,
                $options,
                $matchingContext->templateName
            );

            if (!empty($payload['success_stamp']) && !empty($payload['atoken'])) {
                $this->stampService->apply($documents, $baseUri, $payload['atoken'], $payload['success_stamp']);
            }
        }

        return [
            'documents' => $documents,
            'document_count' => count($documents),
            'rendered_blocks' => count($rendered),
            'debug' => $debug,
            'dry_run' => $options->dryRun,
            'matching_profile' => $payload['profile'] ?? null,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $documents
     * @param array<int, string> $debug
     * @return array<int, array<string, mixed>>
     */
    private function deduplicateDocuments(array $documents, array &$debug = []): array
    {
        $unique = [];
        $seenIds = [];
        $duplicates = 0;

        foreach ($documents as $document) {
            $documentId = isset($document['id']) ? (string) $document['id'] : null;
            if ($documentId === null || $documentId === '') {
                $unique[] = $document;
                continue;
            }

            if (isset($seenIds[$documentId])) {
                $duplicates++;
                continue;
            }

            $seenIds[$documentId] = true;
            $unique[] = $document;
        }

        if ($duplicates > 0) {
            $debug[] = sprintf(
                'Dokumentenliste enthielt %d Dublette(n); Verarbeitung auf %d eindeutige Dokumente reduziert.',
                $duplicates,
                count($unique)
            );
        }

        return $unique;
    }

    private function buildPayload(SyncOptions $options): array
    {
        $token = $this->resolveToken($options);

        $payload = [
            'system' => $options->system,
            'export' => $options->exportTarget,
            'magnetid' => $options->magnetId,
            'template' => $options->template,
            'profile' => $options->profile,
            'profil' => $options->profile,
            'vaultid' => $options->vaultId,
            'folder' => $options->localFolder,
            'ftp_server' => $options->ftpServer,
            'ftp_user' => $options->ftpUser,
            'ftp_password' => $options->ftpPassword,
            'ftp_folder' => $options->ftpFolder,
            'dbhost' => $options->dbHost,
            'dbname' => $options->dbName,
            'dbuser' => $options->dbUser,
            'dbpassword' => $options->dbPassword,
            'success_stamp' => $options->successStampId ?? $options->stampId,
            'error_stamp' => $options->errorStampId,
            'error_attribute' => $options->errorAttributeId,
            'base_uri' => $options->baseUri ?? $this->defaultBaseUri,
            'atoken' => $token,
        ];

        $filtered = array_filter(
            $payload,
            static fn ($value) => $value !== null && $value !== ''
        );

        $filtered['base_uri'] = $options->baseUri ?? $this->defaultBaseUri;

        return $filtered;
    }

    /**
     * @param array<int, array<string, mixed>> $documents
     */
    private function determineLatestChange(array $documents): ?string
    {
        $latest = null;
        foreach ($documents as $document) {
            $changeDate = is_array($document)
                ? ($document['changeDate'] ?? null)
                : ($document->changeDate ?? null);
            if ($changeDate === null) {
                continue;
            }
            if ($latest === null || strcmp($changeDate, $latest) > 0) {
                $latest = $changeDate;
            }
        }

        return $latest;
    }

    private function resolveToken(SyncOptions $options): string
    {
        if ($options->token !== null && $options->token !== '') {
            return $options->token;
        }

        if ($this->defaultApiToken !== null && $this->defaultApiToken !== '') {
            $options->token = $this->defaultApiToken;
            return $this->defaultApiToken;
        }

        $username = $options->apiUsername ?: $this->defaultApiUsername;
        $password = $options->apiPassword ?: $this->defaultApiPassword;
        $baseUri = $options->baseUri ?? $this->defaultBaseUri;

        if ($baseUri === null || $baseUri === '') {
            throw new RuntimeException('Es wurde keine Amagno Base URI konfiguriert.');
        }

        if ($username === null || $username === '' || $password === null || $password === '') {
            throw new RuntimeException('Es ist kein API-Token und kein Benutzer/Passwort für Amagno hinterlegt.');
        }

        $credentialId = $options->credentialId
            ?? $this->credentialStore->getDefaultCredentialId()
            ?? $this->defaultCredentialId;

        if ($credentialId === null) {
            throw new RuntimeException('Es ist keine Credential-ID für Amagno hinterlegt.');
        }

        $this->credentialStore->setCredentials($baseUri, $username, $password, $credentialId);

        $token = $this->tokenProvider
            ->getToken($credentialId)
            ->getTokenString();

        $options->token = $token;

        return $token;
    }

    private function handleError(SyncOptions $options, \Throwable $exception): void
    {
        if ($options->errorStampId === null && $options->errorAttributeId === null) {
            return;
        }

        $baseUri = $options->baseUri ?? $this->defaultBaseUri;
        $token = $options->token;
        $magnetId = $options->magnetId;

        if ($baseUri === null || $token === null || $magnetId === '') {
            return;
        }

        try {
            $documents = $this->documentFetcher->fetchDocuments(
                $magnetId,
                $options->batchSize,
                $options->modifiedSince,
                $token,
                $baseUri
            );

            if ($options->errorStampId !== null) {
                $this->stampService->apply($documents, $baseUri, $token, $options->errorStampId);
            }

            if ($options->errorAttributeId !== null) {
                $message = mb_substr($exception->getMessage(), 0, 500);
                foreach ($documents as $document) {
                    if (!isset($document['id'])) {
                        continue;
                    }
                    $this->tagWriter->writeSingleLineTag(
                        $baseUri,
                        $token,
                        (string) $document['id'],
                        $options->errorAttributeId,
                        $message
                    );
                }
            }
        } catch (\Throwable) {
            // Fehler beim Nachbearbeiten sollen den ursprünglichen Fehler nicht überlagern.
        }
    }
}
