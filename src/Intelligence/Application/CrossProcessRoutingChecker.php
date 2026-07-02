<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ContextSnapshot;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateCrossProcessRoutingRule;

final readonly class CrossProcessRoutingChecker
{
    public const STATUS_SATISFIED = 'SATISFIED';
    public const STATUS_DEVIATION = 'DEVIATION';
    public const STATUS_NOT_APPLICABLE = 'NOT_APPLICABLE';
    public const STATUS_WARNING = 'WARNING';

    public function __construct(
        private DocumentTimelineProvider $timelineProvider,
        private ?ContextSnapshotHistoryProvider $snapshotHistoryProvider = null
    ) {
    }

    public function check(
        string $documentUuid,
        string $sourceProcessKey,
        ProcessTemplate $sourceTemplate,
        ?int $documentVersion = null,
        EventTimelineOrder $order = EventTimelineOrder::DEFAULT
    ): CrossProcessRoutingCheckResult {
        $timeline = $this->timelineProvider->build($documentUuid, $order);
        $events = $timeline->events;
        $instances = $timeline->instances;
        $sourceEvents = array_values(array_filter(
            $events,
            static fn (DocumentTimelineEventRow $event): bool => $event->processKey === $sourceProcessKey
                && ($documentVersion === null || $event->documentVersion === $documentVersion)
        ));
        usort($sourceEvents, static fn (DocumentTimelineEventRow $left, DocumentTimelineEventRow $right): int => $order->compareTimelineRows($left, $right));

        $snapshots = $this->snapshotHistoryProvider?->snapshotsForDocument($documentUuid, $sourceProcessKey) ?? [];
        usort($snapshots, static fn (ContextSnapshot $left, ContextSnapshot $right): int => self::snapshotTime($left) <=> self::snapshotTime($right));

        $ruleResults = [];
        if ($documentVersion === null && $this->hasMultipleDocumentVersions($sourceEvents)) {
            foreach ($sourceTemplate->crossProcessRoutingRules as $rule) {
                $ruleResults[] = $this->ruleResult(
                    self::STATUS_WARNING,
                    $documentUuid,
                    null,
                    $sourceProcessKey,
                    $rule,
                    null,
                    null,
                    ['Multiple source document versions found. Pass --document-version to avoid ambiguous cross-process routing checks.']
                );
            }

            return new CrossProcessRoutingCheckResult(
                $this->aggregateStatus($ruleResults),
                $documentUuid,
                null,
                $sourceProcessKey,
                $ruleResults
            );
        }

        foreach ($sourceTemplate->crossProcessRoutingRules as $rule) {
            $ruleResults[] = $this->checkRule($rule, $documentUuid, $sourceProcessKey, $documentVersion, $sourceEvents, $events, $instances, $snapshots, $order);
        }

        return new CrossProcessRoutingCheckResult(
            $this->aggregateStatus($ruleResults),
            $documentUuid,
            $documentVersion,
            $sourceProcessKey,
            $ruleResults
        );
    }

    /**
     * @param array<int, DocumentTimelineEventRow> $sourceEvents
     * @param array<int, DocumentTimelineEventRow> $allEvents
     * @param array<int, DocumentTimelineInstanceRow> $allInstances
     * @param array<int, ContextSnapshot> $snapshots
     */
    private function checkRule(
        ProcessTemplateCrossProcessRoutingRule $rule,
        string $documentUuid,
        string $sourceProcessKey,
        ?int $requestedDocumentVersion,
        array $sourceEvents,
        array $allEvents,
        array $allInstances,
        array $snapshots,
        EventTimelineOrder $order
    ): CrossProcessRoutingRuleCheckResult {
        $routingEvent = $this->routingEvent($sourceEvents, $rule->afterStep, $order);
        if ($routingEvent === null) {
            return $this->ruleResult(
                self::STATUS_NOT_APPLICABLE,
                $documentUuid,
                $requestedDocumentVersion,
                $sourceProcessKey,
                $rule,
                null,
                null,
                [sprintf('No routing event found at after_step "%s".', $rule->afterStep)]
            );
        }

        $context = $this->contextForRoutingEvent($routingEvent, $snapshots);
        if (!$this->matches($rule->when, $context)) {
            return $this->ruleResult(
                self::STATUS_NOT_APPLICABLE,
                $documentUuid,
                $routingEvent->documentVersion,
                $sourceProcessKey,
                $rule,
                $routingEvent,
                null,
                ['Rule conditions did not match the routing context.']
            );
        }

        $targetEventsSameVersion = $this->targetEvents($allEvents, $rule->expectedProcess, $routingEvent->documentVersion, $order);
        if ($targetEventsSameVersion !== []) {
            $targetStartedAt = $targetEventsSameVersion[0]->occurredAt;
            if ($targetStartedAt < $routingEvent->occurredAt) {
                return $this->ruleResult(
                    self::STATUS_DEVIATION,
                    $documentUuid,
                    $routingEvent->documentVersion,
                    $sourceProcessKey,
                    $rule,
                    $routingEvent,
                    $targetStartedAt,
                    ['Expected target process exists, but starts before the routing event.']
                );
            }

            return $this->ruleResult(
                self::STATUS_SATISFIED,
                $documentUuid,
                $routingEvent->documentVersion,
                $sourceProcessKey,
                $rule,
                $routingEvent,
                $targetStartedAt,
                ['Expected target process exists for the same document version.']
            );
        }

        if ($this->targetInstanceExists($allInstances, $rule->expectedProcess, $routingEvent->documentVersion)) {
            return $this->ruleResult(
                self::STATUS_SATISFIED,
                $documentUuid,
                $routingEvent->documentVersion,
                $sourceProcessKey,
                $rule,
                $routingEvent,
                null,
                ['Expected target process instance exists for the same document version; no target event timestamp is available.']
            );
        }

        $targetEventsOtherVersion = $this->targetEvents($allEvents, $rule->expectedProcess, null, $order);
        if ($targetEventsOtherVersion !== [] || $this->targetInstanceExists($allInstances, $rule->expectedProcess, null)) {
            return $this->ruleResult(
                self::STATUS_WARNING,
                $documentUuid,
                $routingEvent->documentVersion,
                $sourceProcessKey,
                $rule,
                $routingEvent,
                $targetEventsOtherVersion[0]->occurredAt ?? null,
                ['Expected target process exists only for another document version.']
            );
        }

        return $this->ruleResult(
            self::STATUS_DEVIATION,
            $documentUuid,
            $routingEvent->documentVersion,
            $sourceProcessKey,
            $rule,
            $routingEvent,
            null,
            ['Expected target process is missing for this document.']
        );
    }

    /**
     * @param array<int, DocumentTimelineEventRow> $events
     */
    private function routingEvent(array $events, string $afterStep, EventTimelineOrder $order): ?DocumentTimelineEventRow
    {
        $matching = array_values(array_filter(
            $events,
            static fn (DocumentTimelineEventRow $event): bool => $event->stepKey === $afterStep
        ));
        if ($matching === []) {
            return null;
        }

        usort($matching, static fn (DocumentTimelineEventRow $left, DocumentTimelineEventRow $right): int => $order->compareTimelineRows($left, $right));
        foreach ($matching as $event) {
            if ($event->eventPhase === 'after') {
                return $event;
            }
        }

        return $matching[0];
    }

    /**
     * @param array<int, ContextSnapshot> $snapshots
     * @return array<string, mixed>
     */
    private function contextForRoutingEvent(DocumentTimelineEventRow $event, array $snapshots): array
    {
        foreach ($snapshots as $snapshot) {
            if ($snapshot->externalEventKey === $event->externalEventKey) {
                return $snapshot->attributes;
            }
        }

        $bestSnapshot = null;
        foreach ($snapshots as $snapshot) {
            if ($snapshot->document->version !== $event->documentVersion) {
                continue;
            }

            $snapshotTime = self::snapshotTime($snapshot);
            if ($snapshotTime > $event->occurredAt) {
                continue;
            }

            $bestSnapshot = $snapshot;
        }

        if ($bestSnapshot instanceof ContextSnapshot) {
            return $bestSnapshot->attributes;
        }

        $attributes = $event->contextSummary['attributes'] ?? null;

        return is_array($attributes) ? $attributes : [];
    }

    /**
     * @param array<string, mixed> $when
     * @param array<string, mixed> $context
     */
    private function matches(array $when, array $context): bool
    {
        foreach ($when as $field => $expected) {
            if (!array_key_exists($field, $context) || !$this->valuesEqual($context[$field], $expected)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, DocumentTimelineEventRow> $events
     * @return array<int, DocumentTimelineEventRow>
     */
    private function targetEvents(array $events, string $expectedProcess, ?int $documentVersion, EventTimelineOrder $order): array
    {
        $targetEvents = array_values(array_filter(
            $events,
            static fn (DocumentTimelineEventRow $event): bool => $event->processKey === $expectedProcess
                && ($documentVersion === null || $event->documentVersion === $documentVersion)
        ));
        usort($targetEvents, static fn (DocumentTimelineEventRow $left, DocumentTimelineEventRow $right): int => $order->compareTimelineRows($left, $right));

        return $targetEvents;
    }

    /**
     * @param array<int, DocumentTimelineInstanceRow> $instances
     */
    private function targetInstanceExists(array $instances, string $expectedProcess, ?int $documentVersion): bool
    {
        foreach ($instances as $instance) {
            if ($instance->processKey === $expectedProcess && ($documentVersion === null || $instance->documentVersion === $documentVersion)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, DocumentTimelineEventRow> $events
     */
    private function hasMultipleDocumentVersions(array $events): bool
    {
        $versions = [];
        foreach ($events as $event) {
            $versions[$event->documentVersion] = true;
            if (count($versions) > 1) {
                return true;
            }
        }

        return false;
    }

    private function valuesEqual(mixed $actual, mixed $expected): bool
    {
        if ($actual === $expected) {
            return true;
        }

        if ($actual === null || $expected === null || is_array($actual) || is_array($expected) || is_object($actual) || is_object($expected)) {
            return false;
        }

        $actualBool = $this->boolValue($actual);
        $expectedBool = $this->boolValue($expected);
        if ($actualBool !== null || $expectedBool !== null) {
            return $actualBool !== null && $expectedBool !== null && $actualBool === $expectedBool;
        }

        if (is_numeric($actual) && is_numeric($expected)) {
            return (float) $actual === (float) $expected;
        }

        if (is_scalar($actual) && is_scalar($expected)) {
            return (string) $actual === (string) $expected;
        }

        return false;
    }

    private function boolValue(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) && in_array($value, [0, 1], true)) {
            return $value === 1;
        }

        if (!is_string($value)) {
            return null;
        }

        return match (strtolower(trim($value))) {
            'true', '1' => true,
            'false', '0' => false,
            default => null,
        };
    }

    /**
     * @param array<int, CrossProcessRoutingRuleCheckResult> $ruleResults
     */
    private function aggregateStatus(array $ruleResults): string
    {
        if ($ruleResults === []) {
            return self::STATUS_NOT_APPLICABLE;
        }

        $statuses = array_map(static fn (CrossProcessRoutingRuleCheckResult $result): string => $result->status, $ruleResults);
        if (in_array(self::STATUS_DEVIATION, $statuses, true)) {
            return self::STATUS_DEVIATION;
        }
        if (in_array(self::STATUS_WARNING, $statuses, true)) {
            return self::STATUS_WARNING;
        }
        if (in_array(self::STATUS_SATISFIED, $statuses, true)) {
            return self::STATUS_SATISFIED;
        }

        return self::STATUS_NOT_APPLICABLE;
    }

    /**
     * @param array<int, string> $messages
     */
    private function ruleResult(
        string $status,
        string $documentUuid,
        ?int $documentVersion,
        string $sourceProcessKey,
        ProcessTemplateCrossProcessRoutingRule $rule,
        ?DocumentTimelineEventRow $routingEvent,
        ?\DateTimeImmutable $targetStartedAt,
        array $messages
    ): CrossProcessRoutingRuleCheckResult {
        return new CrossProcessRoutingRuleCheckResult(
            $status,
            $documentUuid,
            $documentVersion,
            $sourceProcessKey,
            $rule->key,
            $rule->afterStep,
            $rule->expectedProcess,
            $routingEvent?->occurredAt,
            $targetStartedAt,
            $messages
        );
    }

    private static function snapshotTime(ContextSnapshot $snapshot): \DateTimeImmutable
    {
        return $snapshot->occurredAt ?? $snapshot->capturedAt;
    }
}
