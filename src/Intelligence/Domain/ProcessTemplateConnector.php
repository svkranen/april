<?php

namespace App\Intelligence\Domain;

final readonly class ProcessTemplateConnector
{
    public function __construct(
        public string $type,
        public ?string $connection = null
    ) {
    }
}
