<?php

namespace App\Intelligence\Application;

final readonly class ProcessTemplateCatalogEntry
{
    public function __construct(
        public string $key,
        public string $version,
        public ?string $name,
        public string $path
    ) {
    }
}
