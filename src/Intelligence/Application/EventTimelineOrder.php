<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessEventRecord;

enum EventTimelineOrder: string
{
    case OccurredAt = 'occurred-at';
    case ReceivedAt = 'received-at';
    case OccurredThenReceived = 'occurred-then-received';

    public const DEFAULT = self::OccurredThenReceived;

    public static function fromOption(string $value): ?self
    {
        return self::tryFrom($value);
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $order): string => $order->value, self::cases());
    }

    public function compareProcessEvents(ProcessEventRecord $left, ProcessEventRecord $right): int
    {
        return $this->comparisonTuple(
            $left->occurredAt,
            $left->receivedAt,
            $left->id,
            $left->externalEventKey
        ) <=> $this->comparisonTuple(
            $right->occurredAt,
            $right->receivedAt,
            $right->id,
            $right->externalEventKey
        );
    }

    public function compareTimelineRows(DocumentTimelineEventRow $left, DocumentTimelineEventRow $right): int
    {
        return $this->comparisonTuple(
            $left->occurredAt,
            $left->receivedAt,
            $left->id,
            $left->externalEventKey
        ) <=> $this->comparisonTuple(
            $right->occurredAt,
            $right->receivedAt,
            $right->id,
            $right->externalEventKey
        );
    }

    /**
     * @return array<int, mixed>
     */
    private function comparisonTuple(\DateTimeImmutable $occurredAt, \DateTimeImmutable $receivedAt, ?int $id, string $externalEventKey): array
    {
        $stableId = $id ?? PHP_INT_MAX;

        return match ($this) {
            self::OccurredAt => [$occurredAt, $stableId, $externalEventKey],
            self::ReceivedAt => [$receivedAt, $stableId, $externalEventKey],
            self::OccurredThenReceived => [$occurredAt, $receivedAt, $stableId, $externalEventKey],
        };
    }
}
