<?php

namespace App\Intelligence\Connector\Amagno;

final class AmagnoTagDefinitionResolver
{
    private const DEFINITION_GROUPS = [
        'singleLineStringDefinitions',
        'multiLineStringDefinitions',
        'numberDefinitions',
        'counterDefinitions',
        'dateDefinitions',
        'selectionDefinitions',
        'userSelectionDefinitions',
        'documentTypeSelectionDefinitions',
    ];

    /**
     * @var array<string, array<int, array{id: string, caption: string, type: string}>>
     */
    private array $definitionsByConnection = [];

    public function __construct(
        private readonly AmagnoDocumentGateway $documentGateway
    ) {
    }

    public function resolveByCaption(
        string $caption,
        ?string $token = null,
        ?string $baseUri = null,
        ?int $credentialId = null
    ): AmagnoTagDefinitionLookupResult {
        $caption = trim($caption);
        if ($caption === '') {
            return new AmagnoTagDefinitionLookupResult(null, warning: 'Unknown Amagno tag_name "".');
        }

        $matches = array_values(array_filter(
            $this->definitions($token, $baseUri, $credentialId),
            static fn (array $definition): bool => $definition['caption'] === $caption
        ));

        if ($matches === []) {
            return new AmagnoTagDefinitionLookupResult(
                null,
                warning: sprintf('Unknown Amagno tag_name "%s".', $caption)
            );
        }

        if (count($matches) > 1) {
            return new AmagnoTagDefinitionLookupResult(
                null,
                warning: sprintf('Ambiguous Amagno tag_name "%s".', $caption)
            );
        }

        return new AmagnoTagDefinitionLookupResult($matches[0]['id'], $matches[0]['type']);
    }

    /**
     * @return array<int, array{id: string, caption: string, type: string}>
     */
    private function definitions(?string $token, ?string $baseUri, ?int $credentialId): array
    {
        $cacheKey = implode('|', [$baseUri ?? '', (string) ($credentialId ?? ''), $token ?? '']);
        if (array_key_exists($cacheKey, $this->definitionsByConnection)) {
            return $this->definitionsByConnection[$cacheKey];
        }

        $payload = $this->documentGateway->fetchTagDefinitions($token, $baseUri, $credentialId);
        $definitions = [];
        foreach (self::DEFINITION_GROUPS as $groupType) {
            $group = $payload[$groupType] ?? [];
            if (!is_array($group)) {
                continue;
            }

            foreach ($group as $definition) {
                if (!is_array($definition) || !is_scalar($definition['id'] ?? null) || !is_scalar($definition['caption'] ?? null)) {
                    continue;
                }

                $id = trim((string) $definition['id']);
                $caption = trim((string) $definition['caption']);
                if ($id === '' || $caption === '') {
                    continue;
                }

                $definitions[] = [
                    'id' => $id,
                    'caption' => $caption,
                    'type' => $groupType,
                ];
            }
        }

        return $this->definitionsByConnection[$cacheKey] = $definitions;
    }
}
