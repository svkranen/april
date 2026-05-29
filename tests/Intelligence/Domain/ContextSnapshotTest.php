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

        $snapshot = new ContextSnapshot(
            $document,
            $capturedAt,
            [
                'documentVersion' => 3,
                'costCenter' => 'KST-100',
            ]
        );

        self::assertSame($document, $snapshot->document);
        self::assertSame($capturedAt, $snapshot->capturedAt);
        self::assertSame([
            'documentVersion' => 3,
            'costCenter' => 'KST-100',
        ], $snapshot->attributes);
    }
}
