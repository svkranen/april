<?php

namespace App\Intelligence\Domain;

final readonly class ProcessTemplateStep
{
    public function __construct(
        public string $key,
        public ?string $name = null,
        public string $type = 'normal'
    ) {
    }
}
