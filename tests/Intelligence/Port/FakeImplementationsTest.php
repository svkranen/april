<?php

namespace App\Tests\Intelligence\Port;

use App\Intelligence\Domain\DocumentRef;
use App\Intelligence\Port\ContextProvider;
use App\Intelligence\Port\EventNormalizer;
use App\Intelligence\Port\SignatureVerifier;
use App\Tests\Fake\FakeContextProvider;
use App\Tests\Fake\FakeEventNormalizer;
use App\Tests\Fake\FakeSignatureVerifier;
use PHPUnit\Framework\TestCase;

class FakeImplementationsTest extends TestCase
{
    public function testFakeContextProviderImplementsPortAndFiltersFields(): void
    {
        $provider = new FakeContextProvider([
            'amount' => 12000,
            'costCenter' => 'KST-100',
        ]);

        self::assertInstanceOf(ContextProvider::class, $provider);
        self::assertSame(
            ['amount' => 12000],
            $provider->loadAttributes(new DocumentRef('test', 'doc-1', null, 1), ['amount'])
        );
    }

    public function testFakeEventNormalizerImplementsPort(): void
    {
        $normalizer = new FakeEventNormalizer();
        $event = $normalizer->normalize([
            'external_id' => 'doc-1',
            'step_key' => 'received',
            'occurred_at' => '2026-05-29T10:00:00+00:00',
        ]);

        self::assertInstanceOf(EventNormalizer::class, $normalizer);
        self::assertSame('doc-1', $event->document->externalId);
        self::assertSame('received', $event->stepKey);
    }

    public function testFakeSignatureVerifierImplementsPort(): void
    {
        $verifier = new FakeSignatureVerifier(false);

        self::assertInstanceOf(SignatureVerifier::class, $verifier);
        self::assertFalse($verifier->verify('payload', 'signature'));
    }
}
