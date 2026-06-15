<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessTemplateAccessProbe;
use App\Intelligence\Port\AccessProbeProvider;

final readonly class AccessProbeProviderRegistry
{
    /**
     * @param iterable<AccessProbeProvider> $providers
     */
    public function __construct(
        private iterable $providers = []
    ) {
    }

    public function evaluate(ProcessTemplateAccessProbe $probe, string $documentUuid): AccessProbeResult
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($probe->sourceSystem, $probe->type)) {
                return $provider->evaluate($probe, $documentUuid);
            }
        }

        return AccessProbeResult::skipped('unsupported_probe_type', null, [
            'sourceSystem' => $probe->sourceSystem,
            'type' => $probe->type,
        ]);
    }
}
