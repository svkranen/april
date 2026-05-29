<?php

namespace App\Intelligence\Port;

interface SignatureVerifier
{
    public function verify(string $payload, string $signature): bool;
}
