<?php

namespace App\Service;

use App\Dto\SyncOptions;
use App\Service\Amagno\CredentialStore;
use App\Service\Amagno\DocumentFetcher;
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
        private readonly string $defaultBaseUri,
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
        private readonly CredentialStore $credentialStore
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

        $result = $this->runExport($payload, $options);

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

        $tagCache = [];
        $selectionCache = [];

        $documents = $this->documentFetcher->fetchDocuments(
            $payload['magnetid'],
            $options->batchSize,
            $options->modifiedSince,
            $token
        );

        $tagFetcher = function (string $documentId) use (&$tagCache, $token) {
            if (!array_key_exists($documentId, $tagCache)) {
                $tagCache[$documentId] = $this->documentFetcher->fetchDocumentTags($documentId, $token);
            }

            return $tagCache[$documentId];
        };

        $selectionFetcher = function (string $nodeId) use (&$selectionCache, $token) {
            if (!array_key_exists($nodeId, $selectionCache)) {
                $selectionCache[$nodeId] = $this->documentFetcher->fetchSelectionNode($nodeId, $token);
            }

            return $selectionCache[$nodeId];
        };

        $matrix = $this->matrixBuilder->build(
            $documents,
            $tagFetcher,
            $selectionFetcher,
            $matchingContext->matching
        );

        $debug = [];
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

            if (!empty($payload['stampid']) && !empty($payload['atoken'])) {
                $this->stampService->apply($documents, $this->defaultBaseUri, $payload['atoken'], $payload['stampid']);
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
            'stampid' => $options->stampId,
            'atoken' => $token,
        ];

        return array_filter(
            $payload,
            static fn ($value) => $value !== null && $value !== ''
        );
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

        if ($username === null || $username === '' || $password === null || $password === '') {
            throw new RuntimeException('Es ist kein API-Token und kein Benutzer/Passwort für Amagno hinterlegt.');
        }

        $this->credentialStore->setCredentials($this->defaultBaseUri, $username, $password);

        $token = $this->tokenProvider
            ->getToken($this->credentialStore->getCredentialId())
            ->getTokenString();

        $options->token = $token;

        return $token;
    }
}
