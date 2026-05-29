<?php

namespace App\Intelligence\Port;

use App\Intelligence\Domain\CanonicalEvent;

interface EventNormalizer
{
    /**
     * @param array<string, mixed> $payload
     */
    public function normalize(array $payload): CanonicalEvent;
}
