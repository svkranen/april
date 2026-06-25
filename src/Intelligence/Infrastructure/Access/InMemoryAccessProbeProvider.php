<?php

namespace App\Intelligence\Infrastructure\Access;

use App\Intelligence\Application\AccessProbeResult;
use App\Intelligence\Domain\ProcessTemplateAccessProbe;
use App\Intelligence\Port\AccessProbeProvider;

final readonly class InMemoryAccessProbeProvider implements AccessProbeProvider
{
    /**
     * @param array<int, string> $visibleProbeKeys
     * @param array<int, string> $supportedTypes Entries use "sourceSystem:type".
     * @param array<string, int> $documentCountsByProbeKey
     */
    public function __construct(
        private array $visibleProbeKeys = [],
        private array $supportedTypes = ['fake:fake_document_visibility'],
        private array $documentCountsByProbeKey = []
    ) {
    }

    public function supports(string $sourceSystem, string $type): bool
    {
        return in_array($sourceSystem.':'.$type, $this->supportedTypes, true);
    }

    public function evaluate(ProcessTemplateAccessProbe $probe, string $documentUuid): AccessProbeResult
    {
        $documentCount = $this->documentCountsByProbeKey[$probe->key] ?? null;
        $details = [
            'provider' => 'in_memory',
            'documentUuid' => $documentUuid,
        ];

        return in_array($probe->key, $this->visibleProbeKeys, true)
            ? AccessProbeResult::visible($documentCount, $details)
            : AccessProbeResult::hidden($documentCount, $details);
    }
}
