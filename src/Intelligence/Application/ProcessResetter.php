<?php

namespace App\Intelligence\Application;

interface ProcessResetter
{
    public function reset(string $processKey, ?string $documentUuid = null, bool $dryRun = false): ProcessResetResult;
}
