<?php

namespace App\Intelligence\Infrastructure\Security;

use App\Intelligence\Port\SignatureVerifier;

final class HmacSignatureVerifier implements SignatureVerifier
{
    public function __construct(
        private readonly ?string $secret = null
    ) {
    }

    public function verify(string $payload, string $signature): bool
    {
        if ($this->secret === null || $this->secret === '') {
            return true;
        }

        if ($signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $payload, $this->secret);
        $normalizedSignature = str_starts_with($signature, 'sha256=')
            ? substr($signature, strlen('sha256='))
            : $signature;

        return hash_equals($expected, $normalizedSignature);
    }
}
