<?php

namespace App\Intelligence\Application;

final readonly class ProcessTemplateCatalogResult
{
    /**
     * @param array<int, ProcessTemplateCatalogEntry> $entries
     * @param array<int, array{path: string, message: string}> $warnings
     */
    public function __construct(
        public array $entries,
        public array $warnings
    ) {
    }
}
