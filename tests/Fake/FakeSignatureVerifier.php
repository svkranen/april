<?php

namespace App\Tests\Fake;

use App\Intelligence\Port\SignatureVerifier;

final class FakeSignatureVerifier implements SignatureVerifier
{
    public function __construct(
        private readonly bool $valid = true
    ) {
    }

    public function verify(string $payload, string $signature): bool
    {
        return $this->valid;
    }
}
