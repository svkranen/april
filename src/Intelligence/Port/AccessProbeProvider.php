<?php

namespace App\Intelligence\Port;

use App\Intelligence\Application\AccessProbeResult;
use App\Intelligence\Domain\ProcessTemplateAccessProbe;

interface AccessProbeProvider
{
    public function supports(string $sourceSystem, string $type): bool;

    public function evaluate(ProcessTemplateAccessProbe $probe, string $documentUuid): AccessProbeResult;
}
