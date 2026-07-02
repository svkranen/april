<?php

namespace App\Intelligence\Application;

final readonly class ProcessKeyDocumentsView
{
    /**
     * @param array<int, ProcessKeyDocumentOverviewRow> $rows
     */
    public function __construct(
        public string $processKey,
        public bool $knownTemplate,
        public array $rows
    ) {
    }

    public function hasRows(): bool
    {
        return $this->rows !== [];
    }
}
