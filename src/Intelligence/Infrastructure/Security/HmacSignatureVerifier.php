<?php

namespace App\Intelligence\Infrastructure\Security;

use App\Intelligence\Port\SignatureVerifier;

final class HmacSignatureVerifier implements SignatureVerifier
{
    public function __construct(
        private readonly ?string $secret = null,
        private readonly string $environment = 'prod',
        private readonly bool $allowUnsignedWhenUnconfigured = false
    ) {
    }

    public function verify(string $payload, string $signature): bool
    {
        if ($this->secret === null || $this->secret === '') {
            return $this->allowUnsignedWhenUnconfigured && in_array($this->environment, ['dev', 'test'], true);
        }

        if ($signature === '') {
            return false;
        }

        if (hash_equals($this->secret, $signature)) {
            return true;
        }

        $expected = hash_hmac('sha256', $payload, $this->secret);
        $normalizedSignature = str_starts_with($signature, 'sha256=')
            ? substr($signature, strlen('sha256='))
            : $signature;

        return hash_equals($expected, $normalizedSignature);
    }
}
