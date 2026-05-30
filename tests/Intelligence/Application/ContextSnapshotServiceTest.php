<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\ContextSnapshotService;
use App\Intelligence\Domain\ProcessEventRecord;
use App\Intelligence\Infrastructure\Context\InMemoryContextProfileProvider;
use App\Intelligence\Infrastructure\Context\InMemoryContextSnapshotStore;
use App\Tests\Fake\RecordingContextProvider;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class ContextSnapshotServiceTest extends TestCase
{
    public function testLoadsOnlyDefinedFieldsAndStoresSnapshot(): void
    {
        $contextProvider = new RecordingContextProvider([
            'amount' => 12000,
            'documentType' => 'Invoice',
            'ignored' => 'value',
        ]);
        $store = new InMemoryContextSnapshotStore();
        $service = new ContextSnapshotService(
            new InMemoryContextProfileProvider([
                'invoice-process' => ['amount', 'documentType'],
            ]),
            $contextProvider,
            $store
        );

        $result = $service->captureForEvent($this->event());

        self::assertSame(['amount', 'documentType'], $contextProvider->lastFields);
        self::assertSame('amagno', $contextProvider->lastDocument?->sourceSystem);
        self::assertSame('doc-123', $contextProvider->lastDocument?->externalId);
        self::assertSame('uuid-123', $contextProvider->lastDocument?->externalUuid);
        self::assertSame(2, $contextProvider->lastDocument?->version);
        self::assertSame(1, $store->count());
        self::assertSame([
            'amount' => 12000,
            'documentType' => 'Invoice',
        ], $result->snapshot->attributes);
        self::assertSame([], $result->warnings);
        self::assertSame('invoice-process', $result->snapshot->processKey);
        self::assertSame('evt-1', $result->snapshot->externalEventKey);
    }

    public function testMissingRequiredFieldIsWarning(): void
    {
        $service = new ContextSnapshotService(
            new InMemoryContextProfileProvider([
                'invoice-process' => ['amount', 'costCenter'],
            ]),
            new RecordingContextProvider([
                'amount' => 12000,
            ]),
            new InMemoryContextSnapshotStore()
        );

        $result = $service->captureForEvent($this->event());

        self::assertSame(['Missing required context field "costCenter".'], $result->warnings);
        self::assertSame(['Missing required context field "costCenter".'], $result->snapshot->warnings);
        self::assertSame(['amount' => 12000], $result->snapshot->attributes);
    }

    private function event(): ProcessEventRecord
    {
        return new ProcessEventRecord(
            1,
            'evt-1',
            'amagno',
            'invoice-process',
            'received',
            'received',
            'doc-123',
            'uuid-123',
            2,
            'user-1',
            new DateTimeImmutable('2026-05-29T10:00:00+00:00'),
            new DateTimeImmutable('2026-05-29T10:00:01+00:00'),
            '{}',
            '{}'
        );
    }
}
