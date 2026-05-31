<?php

namespace App\Intelligence\Domain;

final readonly class ProcessTemplateTransition
{
    public function __construct(
        public string $from,
        public ?string $to = null,
        public ?string $toParallelGroup = null
    ) {
    }
}
