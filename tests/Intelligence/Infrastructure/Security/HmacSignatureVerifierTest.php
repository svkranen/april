<?php

namespace App\Tests\Intelligence\Infrastructure\Security;

use App\Intelligence\Infrastructure\Security\HmacSignatureVerifier;
use PHPUnit\Framework\TestCase;

final class HmacSignatureVerifierTest extends TestCase
{
    public function testAcceptsPlainSecretWhenSecretIsConfigured(): void
    {
        $verifier = new HmacSignatureVerifier('shared-secret');

        self::assertTrue($verifier->verify('{"processKey":"invoice"}', 'shared-secret'));
    }

    public function testAcceptsHmacSignatureWhenSecretIsConfigured(): void
    {
        $payload = '{"processKey":"invoice"}';
        $verifier = new HmacSignatureVerifier('shared-secret');

        self::assertTrue($verifier->verify($payload, hash_hmac('sha256', $payload, 'shared-secret')));
        self::assertTrue($verifier->verify($payload, 'sha256='.hash_hmac('sha256', $payload, 'shared-secret')));
    }

    public function testRejectsWrongSignatureWhenSecretIsConfigured(): void
    {
        $verifier = new HmacSignatureVerifier('shared-secret');

        self::assertFalse($verifier->verify('{"processKey":"invoice"}', 'wrong-secret'));
    }
}
