<?php

namespace App\Tests\Fake;

use App\Intelligence\Domain\DocumentRef;
use App\Intelligence\Port\ContextProvider;

final class RecordingContextProvider implements ContextProvider
{
    public ?DocumentRef $lastDocument = null;

    /** @var array<int, string>|null */
    public ?array $lastFields = null;

    public int $calls = 0;

    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        private readonly array $attributes
    ) {
    }

    public function loadAttributes(DocumentRef $document, array $fields): array
    {
        $this->calls++;
        $this->lastDocument = $document;
        $this->lastFields = $fields;

        return array_intersect_key($this->attributes, array_flip($fields));
    }
}
