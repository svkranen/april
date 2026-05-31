<?php

namespace App\Tests\Intelligence\Domain;

use App\Intelligence\Domain\ContextSnapshot;
use App\Intelligence\Domain\DocumentRef;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class ContextSnapshotTest extends TestCase
{
    public function testStoresSnapshotStateAtCaptureTime(): void
    {
        $document = new DocumentRef('amagno', 'doc-123', 'uuid-123', 3);
        $capturedAt = new DateTimeImmutable('2026-05-29T10:05:00+00:00');
        $occurredAt = new DateTimeImmutable('2026-05-29T10:00:00+00:00');
        $loadedAt = $capturedAt;

        $snapshot = new ContextSnapshot(
            $document,
            $capturedAt,
            [
                'documentVersion' => 3,
                'costCenter' => 'KST-100',
            ],
            occurredAt: $occurredAt,
            loadedAt: $loadedAt
        );

        self::assertSame($document, $snapshot->document);
        self::assertSame($capturedAt, $snapshot->capturedAt);
        self::assertSame($occurredAt, $snapshot->occurredAt);
        self::assertSame($loadedAt, $snapshot->loadedAt);
        self::assertSame(300, $snapshot->freshnessSeconds);
        self::assertSame([
            'documentVersion' => 3,
            'costCenter' => 'KST-100',
        ], $snapshot->attributes);
    }

    public function testFreshnessFromUtcTimestampsIsPositiveForBerlinLocalEvent(): void
    {
        $snapshot = new ContextSnapshot(
            new DocumentRef('amagno', 'doc-123', 'uuid-123', 1),
            new DateTimeImmutable('2026-05-31T05:11:44+00:00'),
            occurredAt: new DateTimeImmutable('2026-05-31T05:08:00+00:00'),
            loadedAt: new DateTimeImmutable('2026-05-31T05:11:44+00:00')
        );

        self::assertSame(224, $snapshot->freshnessSeconds);
    }

    public function testFreshnessProvidedFromOldStorageIsRecalculatedFromTimestamps(): void
    {
        $snapshot = new ContextSnapshot(
            new DocumentRef('amagno', 'doc-123', 'uuid-123', 1),
            new DateTimeImmutable('2026-05-31T05:11:44+00:00'),
            occurredAt: new DateTimeImmutable('2026-05-31T05:08:00+00:00'),
            loadedAt: new DateTimeImmutable('2026-05-31T05:11:44+00:00'),
            freshnessSeconds: -6976
        );

        self::assertSame(224, $snapshot->freshnessSeconds);
    }

    public function testFreshDecisionCheckRequiresNonNegativeFreshnessWithinWindow(): void
    {
        self::assertFalse($this->snapshotWithFreshness(-1)->isFreshForDecisionCheck);
        self::assertTrue($this->snapshotWithFreshness(0)->isFreshForDecisionCheck);
        self::assertTrue($this->snapshotWithFreshness(300)->isFreshForDecisionCheck);
        self::assertFalse($this->snapshotWithFreshness(301)->isFreshForDecisionCheck);
    }

    private function snapshotWithFreshness(int $freshnessSeconds): ContextSnapshot
    {
        return new ContextSnapshot(
            new DocumentRef('amagno', 'doc-123', 'uuid-123', 1),
            new DateTimeImmutable('2026-05-31T05:00:00+00:00'),
            occurredAt: new DateTimeImmutable('2026-05-31T05:00:00+00:00'),
            loadedAt: new DateTimeImmutable('2026-05-31T05:00:00+00:00'),
            freshnessSeconds: $freshnessSeconds,
            isFreshForDecisionCheck: $freshnessSeconds >= 0 && $freshnessSeconds <= 300
        );
    }
}
