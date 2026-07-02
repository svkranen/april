<?php

namespace App\Intelligence\Application;

final readonly class ProcessKeyOverviewView
{
    /**
     * @param array<int, ProcessKeyOverviewRow> $rows
     */
    public function __construct(
        public array $rows
    ) {
    }

    public function hasRows(): bool
    {
        return $this->rows !== [];
    }
}
