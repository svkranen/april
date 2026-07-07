<?php

namespace App\Service\SignatureCheck;

use App\Dto\SignatureCheckOptions;
use App\Service\Amagno\ApiTokenProviderInterface;
use App\Service\Amagno\CredentialStoreInterface;
use App\Service\Amagno\DocumentGatewayInterface;
use App\Service\Amagno\DocumentTagWriter;
use App\Service\Checkpoint\CheckpointStore;
use App\Service\Processing\StampService;
use App\SignatureCheck\AmagnoTagValueExtractor;
use App\SignatureCheck\SignatureCompletenessChecker;
use DateTimeImmutable;
use RuntimeException;

class AmagnoSignatureCheckService implements SignatureCheckServiceInterface
{
    public function __construct(
        private readonly ?string $defaultBaseUri,
        private readonly ?int $defaultCredentialId,
        private readonly ?string $defaultApiToken,
        private readonly ?string $defaultApiUsername,
        private readonly ?string $defaultApiPassword,
        private readonly DocumentGatewayInterface $documentFetcher,
        private readonly DocumentTagWriter $tagWriter,
        private readonly StampService $stampService,
        private readonly CheckpointStore $checkpointStore,
        private readonly ApiTokenProviderInterface $tokenProvider,
        private readonly CredentialStoreInterface $credentialStore,
        private readonly AmagnoTagValueExtractor $tagValueExtractor,
        private readonly SignatureCompletenessChecker $checker
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function check(SignatureCheckOptions $options): array
    {
        $checkpointApplied = null;
        if ($options->useCheckpoint && $options->modifiedSince === null) {
            $checkpointData = $this->checkpointStore->read($options->checkpointKey());
            if ($checkpointData !== null && isset($checkpointData['last_change'])) {
                $options->modifiedSince = new DateTimeImmutable($checkpointData['last_change']);
                $checkpointApplied = $options->modifiedSince;
            }
        }

        $token = $this->resolveToken($options);
        $baseUri = $options->baseUri ?? $this->defaultBaseUri;
        if ($baseUri === null || $baseUri === '') {
            throw new RuntimeException('Es wurde keine Amagno Base URI angegeben.');
        }

        $documents = $this->documentFetcher->fetchDocuments(
            $options->magnetId,
            $options->batchSize,
            $options->modifiedSince,
            $token,
            $baseUri
        );

        $selectionCache = [];
        $results = [];
        $completeDocuments = [];
        $incompleteDocuments = [];

        foreach ($documents as $document) {
            $documentId = (string) ($document['id'] ?? '');
            if ($documentId === '') {
                continue;
            }

            $tags = $this->documentFetcher->fetchDocumentTags($documentId, $token, $baseUri);
            $selectionResolver = function (string $nodeId) use (&$selectionCache, $token, $baseUri): array {
                if (!isset($selectionCache[$nodeId])) {
                    $selectionCache[$nodeId] = $this->documentFetcher->fetchSelectionNode($nodeId, $token, $baseUri);
                }

                return $selectionCache[$nodeId];
            };

            $requiredNames = $this->tagValueExtractor->extractValues($tags, $options->requiredTagId, $selectionResolver);
            $confirmedNames = $this->tagValueExtractor->extractValues($tags, $options->confirmedTagId, $selectionResolver);
            $check = $this->checker->check($requiredNames, $confirmedNames);
            $message = $check->message();

            $results[] = [
                'document_id' => $documentId,
                'document_number' => $document['documentNumber'] ?? null,
                'complete' => $check->isComplete(),
                'required_names' => $requiredNames,
                'confirmed_names' => $confirmedNames,
                'missing_names' => $check->missingNames,
                'unexpected_names' => $check->unexpectedNames,
                'message' => $message,
            ];

            if ($check->isComplete()) {
                $completeDocuments[] = $document;
            } else {
                $incompleteDocuments[] = $document;
            }

            if ($options->dryRun) {
                continue;
            }

            if ($options->resultAttributeId !== null) {
                $this->tagWriter->writeSingleLineTag(
                    $baseUri,
                    $token,
                    $documentId,
                    $options->resultAttributeId,
                    mb_substr($message, 0, 500)
                );
            }
        }

        if (!$options->dryRun) {
            if ($options->completeStampId !== null && $completeDocuments !== []) {
                $this->stampService->apply($completeDocuments, $baseUri, $token, $options->completeStampId);
            }

            if ($options->incompleteStampId !== null && $incompleteDocuments !== []) {
                $this->stampService->apply($incompleteDocuments, $baseUri, $token, $options->incompleteStampId);
            }
        }

        $latestChange = $this->determineLatestChange($documents);
        if ($options->useCheckpoint && !$options->dryRun && $latestChange !== null) {
            $this->checkpointStore->write($options->checkpointKey(), [
                'last_run' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
                'last_change' => $latestChange,
            ]);
        }

        $response = [
            'documents' => $results,
            'document_count' => count($results),
            'complete_count' => count($completeDocuments),
            'incomplete_count' => count($incompleteDocuments),
            'dry_run' => $options->dryRun,
        ];

        if ($checkpointApplied !== null) {
            $response['checkpoint_from'] = $checkpointApplied->format(DateTimeImmutable::ATOM);
        }
        if ($options->useCheckpoint && !$options->dryRun && $latestChange !== null) {
            $response['checkpoint_updated'] = $latestChange;
        }

        return $response;
    }

    private function resolveToken(SignatureCheckOptions $options): string
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
            throw new RuntimeException('Es ist kein API-Token und kein Benutzer/Passwort fuer Amagno hinterlegt.');
        }

        $credentialId = $options->credentialId
            ?? $this->credentialStore->getDefaultCredentialId()
            ?? $this->defaultCredentialId;

        if ($credentialId === null) {
            throw new RuntimeException('Es ist keine Credential-ID fuer Amagno hinterlegt.');
        }

        $this->credentialStore->setCredentials($baseUri, $username, $password, $credentialId);

        $token = $this->tokenProvider->tokenForCredential($credentialId);

        $options->token = $token;

        return $token;
    }

    /**
     * @param array<int, array<string, mixed>> $documents
     */
    private function determineLatestChange(array $documents): ?string
    {
        $latest = null;
        foreach ($documents as $document) {
            $changeDate = $document['changeDate'] ?? null;
            if (!is_string($changeDate) || $changeDate === '') {
                continue;
            }

            if ($latest === null || strcmp($changeDate, $latest) > 0) {
                $latest = $changeDate;
            }
        }

        return $latest;
    }
}
