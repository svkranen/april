<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\ContextDiffBuilder;
use App\Intelligence\Application\ContextHistoryEntry;
use App\Intelligence\Application\ContextHistoryReport;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ContextDiffBuilderTest extends TestCase
{
    public function testDetectsAddedChangedRemovedAndUnchangedFields(): void
    {
        $diff = (new ContextDiffBuilder())->build(new ContextHistoryReport('uuid-1', 'invoice', [
            $this->entry('2026-06-01T09:00:00+00:00', ['amount_net' => 400.0, 'export_status' => 'ready']),
            $this->entry('2026-06-01T09:05:00+00:00', ['amount_net' => 400.0, 'export_status' => 'picked_up', 'batch_id' => 'b-1']),
            $this->entry('2026-06-01T09:10:00+00:00', ['amount_net' => 400.0, 'batch_id' => 'b-1']),
        ]));

        self::assertArrayHasKey('batch_id', $diff->addedFields);
        self::assertSame('picked_up', $diff->changedFields['export_status'][0]['to']);
        self::assertArrayHasKey('export_status', $diff->removedFields);
        self::assertSame(400.0, $diff->unchangedFields['amount_net']);
    }

    public function testMissingFieldAndExplicitNullRemainDistinct(): void
    {
        $diff = (new ContextDiffBuilder())->build(new ContextHistoryReport('uuid-1', 'invoice', [
            $this->entry('2026-06-01T09:00:00+00:00', []),
            $this->entry('2026-06-01T09:05:00+00:00', ['nullable_field' => null]),
        ]));

        self::assertArrayHasKey('nullable_field', $diff->addedFields);
        self::assertSame(null, $diff->addedFields['nullable_field'][0]['value']);
        self::assertFalse($diff->fieldHistory['nullable_field'][0]['exists']);
        self::assertTrue($diff->fieldHistory['nullable_field'][1]['exists']);
    }

    public function testArraysAndObjectsAreComparedDeterministically(): void
    {
        $diff = (new ContextDiffBuilder())->build(new ContextHistoryReport('uuid-1', 'invoice', [
            $this->entry('2026-06-01T09:00:00+00:00', ['payload' => ['b' => 2, 'a' => ['y' => 2, 'x' => 1]]]),
            $this->entry('2026-06-01T09:05:00+00:00', ['payload' => ['a' => ['x' => 1, 'y' => 2], 'b' => 2]]),
        ]));

        self::assertArrayNotHasKey('payload', $diff->changedFields);
        self::assertArrayHasKey('payload', $diff->unchangedFields);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function entry(string $at, array $context): ContextHistoryEntry
    {
        return new ContextHistoryEntry(new DateTimeImmutable($at), null, null, null, 1, null, $context, []);
    }
}
