<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\DocumentTimelineContextDiffBuilder;
use App\Intelligence\Application\DocumentTimelineEventRow;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class DocumentTimelineContextDiffBuilderTest extends TestCase
{
    public function testDetectsAddedChangedRemovedAndUnchangedFieldsBetweenEventSnapshots(): void
    {
        $diffs = (new DocumentTimelineContextDiffBuilder())->build([
            $this->event('evt-1', ['amount_net' => 4149788, 'status' => 'new', 'cost_center' => '100']),
            $this->event('evt-2', ['amount_net' => 41.49, 'project' => 'P-1', 'status' => 'new']),
        ]);

        self::assertSame([], $diffs['evt-1']);
        self::assertSame([
            [
                'field' => 'amount_net',
                'type' => 'changed',
                'from' => 4149788,
                'to' => 41.49,
            ],
            [
                'field' => 'cost_center',
                'type' => 'removed',
                'from' => '100',
                'to' => null,
            ],
            [
                'field' => 'project',
                'type' => 'added',
                'from' => null,
                'to' => 'P-1',
            ],
        ], $diffs['evt-2']);
    }

    public function testMissingFieldAndExplicitNullRemainDistinct(): void
    {
        $diffs = (new DocumentTimelineContextDiffBuilder())->build([
            $this->event('evt-1', []),
            $this->event('evt-2', ['nullable_field' => null]),
        ]);

        self::assertSame([
            [
                'field' => 'nullable_field',
                'type' => 'added',
                'from' => null,
                'to' => null,
            ],
        ], $diffs['evt-2']);
    }

    public function testEventsWithoutSnapshotDoNotResetPreviousSnapshot(): void
    {
        $diffs = (new DocumentTimelineContextDiffBuilder())->build([
            $this->event('evt-1', ['amount_net' => 100]),
            $this->event('evt-without-context', null),
            $this->event('evt-2', ['amount_net' => 200]),
        ]);

        self::assertArrayNotHasKey('evt-without-context', $diffs);
        self::assertSame(100, $diffs['evt-2'][0]['from']);
        self::assertSame(200, $diffs['evt-2'][0]['to']);
    }

    /**
     * @param array<string, mixed>|null $context
     */
    private function event(string $externalEventKey, ?array $context): DocumentTimelineEventRow
    {
        return new DocumentTimelineEventRow(
            $externalEventKey,
            'event',
            'step',
            'invoice-process',
            1,
            new DateTimeImmutable('2026-06-01T09:00:00+00:00'),
            new DateTimeImmutable('2026-06-01T09:00:00+00:00'),
            null,
            null,
            $context === null ? null : [
                'attributes' => $context,
                'fields' => array_keys($context),
                'warnings' => [],
            ]
        );
    }
}
