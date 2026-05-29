<?php

namespace App\Intelligence\Infrastructure\Amagno;

use App\Intelligence\Domain\DocumentRef;
use App\Intelligence\Port\ContextProvider;
use App\Service\Amagno\DocumentFetcher;

final class AmagnoContextProvider implements ContextProvider
{
    /**
     * @param array<string, string> $fieldMap
     */
    public function __construct(
        private readonly DocumentFetcher $documentFetcher,
        private readonly AmagnoTagValueResolver $tagValueResolver,
        private readonly array $fieldMap = [],
        private readonly ?string $token = null,
        private readonly ?string $baseUri = null
    ) {
    }

    public function loadAttributes(DocumentRef $document, array $fields): array
    {
        $fields = array_values(array_unique(array_filter($fields, static fn (string $field): bool => $field !== '')));
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

        $tags = $this->documentFetcher->fetchDocumentTags($document->externalId, $this->token, $this->baseUri);
        $selectionCache = [];
        $selectionResolver = function (string $nodeId) use (&$selectionCache): array {
            if (!array_key_exists($nodeId, $selectionCache)) {
                $selectionCache[$nodeId] = $this->documentFetcher->fetchSelectionNode($nodeId, $this->token, $this->baseUri);
            }

            return $selectionCache[$nodeId];
        };

        foreach ($tagFields as $field) {
            $tagDefinitionId = $this->tagDefinitionIdFor($field);
            if ($tagDefinitionId === null) {
                continue;
            }

            $values = $this->tagValueResolver->resolveValues($tags, $tagDefinitionId, $selectionResolver);
            $attributes[$field] = count($values) === 1 ? $values[0] : $values;
        }

        return $this->orderByRequestedFields($attributes, $fields);
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
        $tagDefinitionId = $this->fieldMap[$field] ?? $field;

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
