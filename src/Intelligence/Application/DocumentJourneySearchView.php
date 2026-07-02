<?php

namespace App\Intelligence\Application;

final readonly class DocumentJourneySearchView
{
    public function __construct(
        public ?string $query = null,
        public ?string $error = null
    ) {
    }
}
