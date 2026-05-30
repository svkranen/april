<?php

namespace App\Intelligence\Connector\Amagno;

use App\Intelligence\Application\ContextProviderWarningProvider;
use App\Intelligence\Domain\DocumentRef;
use App\Intelligence\Port\ContextProvider;
use Psr\Log\LoggerInterface;

final class AmagnoContextProvider implements ContextProvider, ContextProviderWarningProvider
{
    /**
     * @param array<string, AmagnoFieldMapping|string> $fieldMap
     */
    public function __construct(
        private readonly AmagnoDocumentGateway $documentGateway,
        private readonly AmagnoTagValueResolver $tagValueResolver,
        private readonly AmagnoTagDefinitionResolver $tagDefinitionResolver,
        private readonly array $fieldMap = [],
        private readonly ?string $token = null,
        private readonly ?string $baseUri = null,
        private readonly ?int $credentialId = null,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    /** @var array<int, string> */
    private array $warnings = [];

    public function loadAttributes(DocumentRef $document, array $fields): array
    {
        $fields = array_values(array_unique(array_filter($fields, static fn (string $field): bool => $field !== '')));
        $this->warnings = [];
        if ($fields === []) {
            return [];
        }

        $attributes = $this->loadDocumentFields($document, $fields);
        $tagFields = array_values(array_filter(
            $fields,
            fn (string $field): bool => !array_key_exists($field, $attributes) && $this->tagDefinitionIdFor($field) !== null
        ));

        if ($tagFields === []) {
            return $this->orderByRequestedFields($attributes, $fields);
        }

        $tagDefinitionIdsByField = [];
        foreach ($tagFields as $field) {
            $tagDefinitionId = $this->tagDefinitionIdFor($field);
            if ($tagDefinitionId !== null) {
                $tagDefinitionIdsByField[$field] = $tagDefinitionId;
            }
        }

        if ($tagDefinitionIdsByField === []) {
            return $this->orderByRequestedFields($attributes, $fields);
        }

        if ($document->externalUuid === null || $document->externalUuid === '') {
            $this->logger?->warning('Cannot load Amagno tags without document UUID.', [
                'document_id' => $document->externalId,
                'requested_fields' => $fields,
            ]);

            return $this->orderByRequestedFields($attributes, $fields);
        }

        $tags = $this->documentGateway->fetchDocumentTags($document->externalUuid, $this->token, $this->baseUri, $this->credentialId);
        $selectionCache = [];
        $selectionResolver = function (string $nodeId) use (&$selectionCache): array {
            if (!array_key_exists($nodeId, $selectionCache)) {
                $selectionCache[$nodeId] = $this->documentGateway->fetchSelectionNode($nodeId, $this->token, $this->baseUri, $this->credentialId);
            }

            return $selectionCache[$nodeId];
        };

        foreach ($tagDefinitionIdsByField as $field => $tagDefinitionId) {
            $values = $this->tagValueResolver->resolveValues($tags, $tagDefinitionId, $selectionResolver);
            $attributes[$field] = count($values) === 1 ? $values[0] : $values;
        }

        $this->logger?->debug('Loaded tag values', [
            'document_id' => $document->externalId,
            'requested_fields' => $fields,
            'field_map' => $this->fieldMap,
            'attributes' => $attributes,
        ]);

        return $this->orderByRequestedFields($attributes, $fields);
    }

    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @param array<int, string> $fields
     * @return array<string, mixed>
     */
    private function loadDocumentFields(DocumentRef $document, array $fields): array
    {
        $attributes = [];

        foreach ($fields as $field) {
            if ($field === 'documentVersion') {
                $attributes[$field] = $document->version;
            } elseif ($field === 'documentId') {
                $attributes[$field] = $document->externalId;
            } elseif ($field === 'documentUuid') {
                $attributes[$field] = $document->externalUuid;
            } elseif ($field === 'approvals' || $field === 'signatures') {
                $attributes[$field] = [];
            }
        }

        return $attributes;
    }

    private function tagDefinitionIdFor(string $field): ?string
    {
        $mapping = $this->fieldMap[$field] ?? null;
        if ($mapping instanceof AmagnoFieldMapping) {
            if ($mapping->tagId !== null && $mapping->tagId !== '') {
                return $mapping->tagId;
            }

            if ($mapping->tagName !== null && $mapping->tagName !== '') {
                $result = $this->tagDefinitionResolver->resolveByCaption(
                    $mapping->tagName,
                    $this->token,
                    $this->baseUri,
                    $this->credentialId
                );
                if ($result->warning !== null) {
                    $this->warnings[] = $result->warning;
                    $this->logger?->warning($result->warning, [
                        'field' => $field,
                        'tag_name' => $mapping->tagName,
                    ]);
                }

                return $result->tagDefinitionId;
            }

            return null;
        }

        $tagDefinitionId = is_string($mapping) ? $mapping : $field;

        return $tagDefinitionId === '' ? null : $tagDefinitionId;
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<int, string> $fields
     * @return array<string, mixed>
     */
    private function orderByRequestedFields(array $attributes, array $fields): array
    {
        $ordered = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $attributes)) {
                $ordered[$field] = $attributes[$field];
            }
        }

        return $ordered;
    }
}
