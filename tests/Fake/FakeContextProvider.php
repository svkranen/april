<?php

namespace App\Tests\Fake;

use App\Intelligence\Domain\DocumentRef;
use App\Intelligence\Port\ContextProvider;

final class FakeContextProvider implements ContextProvider
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        private readonly array $attributes = []
    ) {
    }

    public function loadAttributes(DocumentRef $document, array $fields): array
    {
        if ($fields === []) {
            return $this->attributes;
        }

        return array_intersect_key($this->attributes, array_flip($fields));
    }
}
