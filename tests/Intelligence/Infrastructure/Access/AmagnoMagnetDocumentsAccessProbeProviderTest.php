<?php

namespace App\Tests\Intelligence\Infrastructure\Access;

use App\Intelligence\Application\AccessProbeProviderRegistry;
use App\Intelligence\Application\AccessProbeResult;
use App\Intelligence\Connector\Amagno\AmagnoDocumentGateway;
use App\Intelligence\Domain\ProcessTemplateAccessProbe;
use App\Intelligence\Infrastructure\Access\AmagnoMagnetDocumentsAccessProbeProvider;
use App\Intelligence\Infrastructure\Access\InMemoryAccessProbeProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AmagnoMagnetDocumentsAccessProbeProviderTest extends TestCase
{
    public function testFindsDocumentUuidField(): void
    {
        $gateway = new RecordingAmagnoDocumentGateway([
            0 => [['documentUuid' => 'doc-uuid-1']],
        ]);
        $provider = new AmagnoMagnetDocumentsAccessProbeProvider($gateway);

        $result = $provider->evaluate($this->probe(), 'doc-uuid-1');

        self::assertSame(AccessProbeResult::ACTUAL_VISIBLE, $result->actual);
        self::assertSame(1, $result->documentCount);
        self::assertSame([['magnetId' => '1001', 'limit' => 50, 'offset' => 0]], $gateway->calls);
    }

    public function testFindsUuidField(): void
    {
        $provider = new AmagnoMagnetDocumentsAccessProbeProvider(new RecordingAmagnoDocumentGateway([
            0 => [['uuid' => 'doc-uuid-1']],
        ]));

        $result = $provider->evaluate($this->probe(), 'doc-uuid-1');

        self::assertSame(AccessProbeResult::ACTUAL_VISIBLE, $result->actual);
    }

    public function testFindsDocumentOnSecondPage(): void
    {
        $gateway = new RecordingAmagnoDocumentGateway([
            0 => [
                ['documentUuid' => 'page-1-doc-1'],
                ['documentUuid' => 'page-1-doc-2'],
            ],
            2 => [
                ['documentUuid' => 'doc-uuid-1'],
            ],
        ]);
        $provider = new AmagnoMagnetDocumentsAccessProbeProvider($gateway);

        $result = $provider->evaluate($this->probe(maxDocuments: 10, options: ['page_size' => 2]), 'doc-uuid-1');

        self::assertSame(AccessProbeResult::ACTUAL_VISIBLE, $result->actual);
        self::assertSame(3, $result->documentCount);
        self::assertSame([
            ['magnetId' => '1001', 'limit' => 2, 'offset' => 0],
            ['magnetId' => '1001', 'limit' => 2, 'offset' => 2],
        ], $gateway->calls);
    }

    public function testDoesNotTreatNonUuidDocumentIdAsDocumentUuid(): void
    {
        $provider = new AmagnoMagnetDocumentsAccessProbeProvider(new RecordingAmagnoDocumentGateway([
            0 => [['documentId' => 'doc-uuid-1']],
        ]));

        $result = $provider->evaluate($this->probe(), 'doc-uuid-1');

        self::assertSame(AccessProbeResult::ACTUAL_HIDDEN, $result->actual);
    }

    public function testUsesIdFallbackOnlyForUuidShapedValues(): void
    {
        $uuid = '11111111-2222-3333-4444-555555555555';
        $provider = new AmagnoMagnetDocumentsAccessProbeProvider(new RecordingAmagnoDocumentGateway([
            0 => [['id' => $uuid]],
        ]));

        $result = $provider->evaluate($this->probe(), $uuid);

        self::assertSame(AccessProbeResult::ACTUAL_VISIBLE, $result->actual);
    }

    public function testReturnsHiddenWhenDocumentIsNotContained(): void
    {
        $gateway = new RecordingAmagnoDocumentGateway([
            0 => [
                ['documentUuid' => 'other-doc-1'],
                ['documentUuid' => 'other-doc-2'],
            ],
            2 => [
                ['documentUuid' => 'other-doc-3'],
            ],
        ]);
        $provider = new AmagnoMagnetDocumentsAccessProbeProvider($gateway);

        $result = $provider->evaluate($this->probe(maxDocuments: 10, options: ['page_size' => 2]), 'doc-uuid-1');

        self::assertSame(AccessProbeResult::ACTUAL_HIDDEN, $result->actual);
        self::assertSame(3, $result->documentCount);
    }

    public function testMissingMagnetIdIsSkipped(): void
    {
        $gateway = $this->createMock(AmagnoDocumentGateway::class);
        $gateway->expects(self::never())->method('fetchDocuments');
        $provider = new AmagnoMagnetDocumentsAccessProbeProvider($gateway);

        $result = $provider->evaluate(new ProcessTemplateAccessProbe('probe', 'amagno', 'amagno_magnet_documents'), 'doc-uuid-1');

        self::assertSame(AccessProbeResult::ACTUAL_SKIPPED, $result->actual);
        self::assertSame('missing_magnet_id', $result->reason);
    }

    public function testScanLimitReachedAfterFullPagesIsSkipped(): void
    {
        $provider = new AmagnoMagnetDocumentsAccessProbeProvider(new RecordingAmagnoDocumentGateway([
            0 => [
                ['documentUuid' => 'doc-1'],
                ['documentUuid' => 'doc-2'],
            ],
            2 => [
                ['documentUuid' => 'doc-3'],
                ['documentUuid' => 'doc-4'],
            ],
        ]));

        $result = $provider->evaluate($this->probe(maxDocuments: 4, options: ['page_size' => 2]), 'doc-missing');

        self::assertSame(AccessProbeResult::ACTUAL_SKIPPED, $result->actual);
        self::assertSame('probe_scan_limit_reached', $result->reason);
        self::assertSame(4, $result->documentCount);
    }

    public function testPageSizeDefaultsToFifty(): void
    {
        $gateway = new RecordingAmagnoDocumentGateway([
            0 => [],
        ]);
        $provider = new AmagnoMagnetDocumentsAccessProbeProvider($gateway);

        $provider->evaluate($this->probe(), 'doc-uuid-1');

        self::assertSame([['magnetId' => '1001', 'limit' => 50, 'offset' => 0]], $gateway->calls);
    }

    public function testPageSizeAboveFiveHundredIsEffectivelyLimitedToFiveHundred(): void
    {
        $gateway = new RecordingAmagnoDocumentGateway([
            0 => $this->documents(500),
            500 => [['documentUuid' => 'doc-uuid-1']],
        ]);
        $provider = new AmagnoMagnetDocumentsAccessProbeProvider($gateway);

        $result = $provider->evaluate($this->probe(maxDocuments: 600, options: ['page_size' => 600]), 'doc-uuid-1');

        self::assertSame(AccessProbeResult::ACTUAL_VISIBLE, $result->actual);
        self::assertSame([
            ['magnetId' => '1001', 'limit' => 500, 'offset' => 0],
            ['magnetId' => '1001', 'limit' => 500, 'offset' => 500],
        ], $gateway->calls);
    }

    public function testPageSizeAboveMaxDocumentsIsEffectivelyLimitedToMaxDocuments(): void
    {
        $gateway = new RecordingAmagnoDocumentGateway([
            0 => $this->documents(25),
        ]);
        $provider = new AmagnoMagnetDocumentsAccessProbeProvider($gateway);

        $result = $provider->evaluate($this->probe(maxDocuments: 25, options: ['page_size' => 50]), 'doc-missing');

        self::assertSame(AccessProbeResult::ACTUAL_SKIPPED, $result->actual);
        self::assertSame('probe_scan_limit_reached', $result->reason);
        self::assertSame([['magnetId' => '1001', 'limit' => 25, 'offset' => 0]], $gateway->calls);
    }

    public function testInvalidMaxDocumentsIsSkippedWithoutApiCall(): void
    {
        $gateway = new RecordingAmagnoDocumentGateway([]);
        $provider = new AmagnoMagnetDocumentsAccessProbeProvider($gateway);

        $result = $provider->evaluate($this->probe(maxDocuments: 0), 'doc-uuid-1');

        self::assertSame(AccessProbeResult::ACTUAL_SKIPPED, $result->actual);
        self::assertSame('invalid_max_documents', $result->reason);
        self::assertSame([], $gateway->calls);
    }

    public function testApiExceptionIsUnknown(): void
    {
        $gateway = $this->createMock(AmagnoDocumentGateway::class);
        $gateway
            ->expects(self::once())
            ->method('fetchDocuments')
            ->willThrowException(new RuntimeException('API failed'));
        $provider = new AmagnoMagnetDocumentsAccessProbeProvider($gateway);

        $result = $provider->evaluate($this->probe(), 'doc-uuid-1');

        self::assertSame(AccessProbeResult::ACTUAL_UNKNOWN, $result->actual);
        self::assertSame('api_error', $result->reason);
    }

    public function testRegistrySelectsAmagnoProvider(): void
    {
        $registry = new AccessProbeProviderRegistry([
            new AmagnoMagnetDocumentsAccessProbeProvider(new RecordingAmagnoDocumentGateway([
                0 => [['documentUuid' => 'doc-uuid-1']],
            ])),
        ]);

        $result = $registry->evaluate($this->probe(), 'doc-uuid-1');

        self::assertSame(AccessProbeResult::ACTUAL_VISIBLE, $result->actual);
    }

    public function testInMemoryProviderRemainsUsable(): void
    {
        $registry = new AccessProbeProviderRegistry([
            new InMemoryAccessProbeProvider(['fake_probe']),
        ]);

        $result = $registry->evaluate(
            new ProcessTemplateAccessProbe('fake_probe', 'fake', 'fake_document_visibility'),
            'doc-uuid-1'
        );

        self::assertSame(AccessProbeResult::ACTUAL_VISIBLE, $result->actual);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function probe(int $maxDocuments = 500, array $options = []): ProcessTemplateAccessProbe
    {
        return new ProcessTemplateAccessProbe(
            'approval_location_a_today',
            'amagno',
            'amagno_magnet_documents',
            array_merge(['magnet_id' => '1001'], $options),
            $maxDocuments
        );
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function documents(int $count): array
    {
        $documents = [];
        for ($i = 1; $i <= $count; ++$i) {
            $documents[] = ['documentUuid' => 'doc-'.$i];
        }

        return $documents;
    }
}

final class RecordingAmagnoDocumentGateway implements AmagnoDocumentGateway
{
    /**
     * @var array<int, array{magnetId: string, limit: int, offset: int}>
     */
    public array $calls = [];

    /**
     * @param array<int, array<int, array<string, mixed>>> $pagesByOffset
     */
    public function __construct(
        private readonly array $pagesByOffset
    ) {
    }

    public function fetchDocuments(string $magnetId, int $limit = 50, int $offset = 0): array
    {
        $this->calls[] = ['magnetId' => $magnetId, 'limit' => $limit, 'offset' => $offset];

        return $this->pagesByOffset[$offset] ?? [];
    }

    public function fetchDocumentTags(string $documentId, ?string $tokenOverride = null, ?string $baseUriOverride = null, ?int $credentialIdOverride = null): array
    {
        return [];
    }

    public function fetchSelectionNode(string $nodeId, ?string $tokenOverride = null, ?string $baseUriOverride = null, ?int $credentialIdOverride = null): array
    {
        return [];
    }

    public function fetchTagDefinitions(?string $tokenOverride = null, ?string $baseUriOverride = null, ?int $credentialIdOverride = null): array
    {
        return [];
    }
}
