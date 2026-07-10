<?php

namespace App\Intelligence\Domain;

final readonly class ProcessTemplateMatch
{
    /**
     * @param array<int, string> $anyProcessKeys
     */
    public function __construct(
        public array $anyProcessKeys = []
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->anyProcessKeys === [];
    }
}
