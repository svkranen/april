<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessTemplate;

/** Resolves an explicitly versioned Soll template without changing the base provider contract. */
interface ProcessTemplateVersionProvider
{
    public function findByProcessKeyAndVersion(string $processKey, string $version): ?ProcessTemplate;
}
