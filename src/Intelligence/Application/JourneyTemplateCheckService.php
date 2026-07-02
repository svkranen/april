<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ContextSnapshot;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateStep;
use App\Intelligence\Domain\ProcessTemplateTransition;

final readonly class JourneyTemplateCheckService
{
    public const STATUS_SATISFIED = 'SATISFIED';
    public const STATUS_DEVIATION = 'DEVIATION';
    public const STATUS_WARNING = 'WARNING';
    public const STATUS_NOT_APPLICABLE = 'NOT_APPLICABLE';

    public const STEP_PROCESS_EXISTS = 'PROCESS_EXISTS';
    public const STEP_MISSING_REQUIRED_PROCESS = 'MISSING_REQUIRED_PROCESS';
    public const STEP_CONDITION_NOT_APPLICABLE = 'CONDITION_NOT_APPLICABLE';
    public const STEP_WARNING = 'WARNING';

    public const TRANSITION_SATISFIED = 'SATISFIED';
    public const TRANSITION_WRONG_ORDER = 'PROCESS_EXISTS_WRONG_ORDER';
    public const TRANSITION_WARNING = 'WARNING';
    public const TRANSITION_NOT_APPLICABLE = 'NOT_APPLICABLE';

    public function __construct(
        private DocumentTimelineProvider $timelineProvider,
        private ?ContextSnapshotHistoryProvider $snapshotHistoryProvider = null,
        private ?ProcessTemplateProvider $templateProvider = null
    ) {
    }

    public function check(
        string $documentUuid,
        ProcessTemplate $template,
        ?int $documentVersion = null,
        EventTimelineOrder $order = EventTimelineOrder::DEFAULT
    ): JourneyTemplateCheckResult {
        $timeline = $this->timelineProvider->build($documentUuid, $order);
        $events = $timeline->events;
        $instances = $timeline->instances;
        $snapshots = $this->snapshots($documentUuid, $template, $events);

        if ($documentVersion === null && $this->hasMultipleDocumentVersions($events, $instances)) {
            $stepResults = [];
            foreach ($template->steps as $step) {
                if ($step->type !== 'process') {
                    continue;
                }

                $stepResults[] = new JourneyTemplateStepCheckResult(
                    self::STEP_WARNING,
                    $step->key,
                    $step->type,
                    $step->processKey,
                    $step->required,
                    true,
                    $this->hasDetailTemplate($step->processKey),
                    null,
                    messages: ['Multiple document versions found. Pass a document version to avoid ambiguous journey checks.']
                );
            }

            return new JourneyTemplateCheckResult(
                self::STATUS_WARNING,
                $documentUuid,
                null,
                $template->key,
                $stepResults,
                []
            );
        }

        $stepResults = [];
        foreach ($template->steps as $step) {
            if ($step->type !== 'process') {
                continue;
            }

            $stepResults[] = $this->checkProcessStep($step, $events, $instances, $snapshots, $documentVersion, $order);
        }

        $stepResultsByKey = [];
        foreach ($stepResults as $stepResult) {
            $stepResultsByKey[$stepResult->journeyStepKey] = $stepResult;
        }

        $transitionResults = $this->checkTransitions($template->transitions, $stepResultsByKey);

        return new JourneyTemplateCheckResult(
            $this->aggregateStatus($stepResults, $transitionResults),
            $documentUuid,
            $documentVersion,
            $template->key,
            $stepResults,
            $transitionResults
        );
    }

    /**
     * @param array<int, DocumentTimelineEventRow> $events
     * @param array<int, DocumentTimelineInstanceRow> $instances
     * @param array<int, ContextSnapshot> $snapshots
     */
    private function checkProcessStep(
        ProcessTemplateStep $step,
        array $events,
        array $instances,
        array $snapshots,
        ?int $documentVersion,
        EventTimelineOrder $order
    ): JourneyTemplateStepCheckResult {
        if ($step->processKey === null) {
            return new JourneyTemplateStepCheckResult(
                self::STEP_WARNING,
                $step->key,
                $step->type,
                null,
                $step->required,
                true,
                false,
                $documentVersion,
                messages: ['Process journey step has no process_key.']
            );
        }

        $processEvents = $this->eventsForProcess($events, $step->processKey, $documentVersion, $order);
        $processInstances = $this->instancesForProcess($instances, $step->processKey, $documentVersion);
        $context = $this->contextForStep($step, $processEvents[0] ?? null, $events, $snapshots, $documentVersion, $order);
        if (!$this->matches($step->when, $context)) {
            return new JourneyTemplateStepCheckResult(
                self::STEP_CONDITION_NOT_APPLICABLE,
                $step->key,
                $step->type,
                $step->processKey,
                $step->required,
                false,
                $this->hasDetailTemplate($step->processKey),
                $documentVersion,
                messages: ['Step conditions did not match the journey context.']
            );
        }

        if ($processEvents !== []) {
            return new JourneyTemplateStepCheckResult(
                self::STEP_PROCESS_EXISTS,
                $step->key,
                $step->type,
                $step->processKey,
                $step->required,
                true,
                $this->hasDetailTemplate($step->processKey),
                $processEvents[0]->documentVersion,
                $processEvents[0]->occurredAt,
                $processEvents[count($processEvents) - 1]->occurredAt,
                ['Process events exist for this journey step.']
            );
        }

        if ($processInstances !== []) {
            return new JourneyTemplateStepCheckResult(
                self::STEP_PROCESS_EXISTS,
                $step->key,
                $step->type,
                $step->processKey,
                $step->required,
                true,
                $this->hasDetailTemplate($step->processKey),
                $processInstances[0]->documentVersion,
                messages: ['Process instance exists for this journey step; no event timestamp is available.']
            );
        }

        if ($documentVersion !== null && ($this->eventsForProcess($events, $step->processKey, null, $order) !== [] || $this->instancesForProcess($instances, $step->processKey, null) !== [])) {
            return new JourneyTemplateStepCheckResult(
                self::STEP_WARNING,
                $step->key,
                $step->type,
                $step->processKey,
                $step->required,
                true,
                $this->hasDetailTemplate($step->processKey),
                $documentVersion,
                messages: ['Process exists only for another document version.']
            );
        }

        if (!$step->required) {
            return new JourneyTemplateStepCheckResult(
                self::STEP_CONDITION_NOT_APPLICABLE,
                $step->key,
                $step->type,
                $step->processKey,
                false,
                false,
                $this->hasDetailTemplate($step->processKey),
                $documentVersion,
                messages: ['Optional process journey step is not present.']
            );
        }

        return new JourneyTemplateStepCheckResult(
            self::STEP_MISSING_REQUIRED_PROCESS,
            $step->key,
            $step->type,
            $step->processKey,
            true,
            true,
            $this->hasDetailTemplate($step->processKey),
            $documentVersion,
            messages: ['Required process journey step is missing.']
        );
    }

    /**
     * @param array<int, ProcessTemplateTransition> $transitions
     * @param array<string, JourneyTemplateStepCheckResult> $stepResultsByKey
     * @return array<int, JourneyTemplateTransitionCheckResult>
     */
    private function checkTransitions(array $transitions, array $stepResultsByKey): array
    {
        $results = [];
        foreach ($transitions as $transition) {
            if ($transition->to === null) {
                continue;
            }

            $from = $stepResultsByKey[$transition->from] ?? null;
            $to = $stepResultsByKey[$transition->to] ?? null;
            if (!$from instanceof JourneyTemplateStepCheckResult || !$to instanceof JourneyTemplateStepCheckResult) {
                $results[] = new JourneyTemplateTransitionCheckResult(
                    self::TRANSITION_NOT_APPLICABLE,
                    $transition->from,
                    $transition->to,
                    messages: ['Transition references a step that is not part of the process-step journey check.']
                );
                continue;
            }

            if (!$from->applicable || !$to->applicable) {
                $results[] = new JourneyTemplateTransitionCheckResult(
                    self::TRANSITION_NOT_APPLICABLE,
                    $transition->from,
                    $transition->to,
                    $from->startedAt,
                    $to->startedAt,
                    ['At least one transition step is not applicable.']
                );
                continue;
            }

            if ($from->status !== self::STEP_PROCESS_EXISTS || $to->status !== self::STEP_PROCESS_EXISTS) {
                $results[] = new JourneyTemplateTransitionCheckResult(
                    self::TRANSITION_NOT_APPLICABLE,
                    $transition->from,
                    $transition->to,
                    $from->startedAt,
                    $to->startedAt,
                    ['At least one transition step is not satisfied.']
                );
                continue;
            }

            if ($from->startedAt === null || $to->startedAt === null) {
                $results[] = new JourneyTemplateTransitionCheckResult(
                    self::TRANSITION_WARNING,
                    $transition->from,
                    $transition->to,
                    $from->startedAt,
                    $to->startedAt,
                    ['Transition order cannot be checked because at least one process has no event timestamp.']
                );
                continue;
            }

            if ($to->startedAt < $from->startedAt) {
                $results[] = new JourneyTemplateTransitionCheckResult(
                    self::TRANSITION_WRONG_ORDER,
                    $transition->from,
                    $transition->to,
                    $from->startedAt,
                    $to->startedAt,
                    ['Target process starts before source journey step.']
                );
                continue;
            }

            $results[] = new JourneyTemplateTransitionCheckResult(
                self::TRANSITION_SATISFIED,
                $transition->from,
                $transition->to,
                $from->startedAt,
                $to->startedAt,
                ['Transition order is plausible.']
            );
        }

        return $results;
    }

    /**
     * @param array<int, DocumentTimelineEventRow> $events
     * @return array<int, DocumentTimelineEventRow>
     */
    private function eventsForProcess(array $events, string $processKey, ?int $documentVersion, EventTimelineOrder $order): array
    {
        $matching = array_values(array_filter(
            $events,
            static fn (DocumentTimelineEventRow $event): bool => $event->processKey === $processKey
                && ($documentVersion === null || $event->documentVersion === $documentVersion)
        ));
        usort($matching, static fn (DocumentTimelineEventRow $left, DocumentTimelineEventRow $right): int => $order->compareTimelineRows($left, $right));

        return $matching;
    }

    /**
     * @param array<int, DocumentTimelineInstanceRow> $instances
     * @return array<int, DocumentTimelineInstanceRow>
     */
    private function instancesForProcess(array $instances, string $processKey, ?int $documentVersion): array
    {
        $matching = array_values(array_filter(
            $instances,
            static fn (DocumentTimelineInstanceRow $instance): bool => $instance->processKey === $processKey
                && ($documentVersion === null || $instance->documentVersion === $documentVersion)
        ));
        usort($matching, static fn (DocumentTimelineInstanceRow $left, DocumentTimelineInstanceRow $right): int => [$left->documentVersion, $left->id] <=> [$right->documentVersion, $right->id]);

        return $matching;
    }

    /**
     * @param array<int, DocumentTimelineEventRow> $allEvents
     * @param array<int, ContextSnapshot> $snapshots
     * @return array<string, mixed>
     */
    private function contextForStep(
        ProcessTemplateStep $step,
        ?DocumentTimelineEventRow $processEvent,
        array $allEvents,
        array $snapshots,
        ?int $documentVersion,
        EventTimelineOrder $order
    ): array {
        if ($processEvent instanceof DocumentTimelineEventRow) {
            return $this->contextForEvent($processEvent, $snapshots, $allEvents, $order);
        }

        $version = $documentVersion;
        if ($version === null) {
            $versions = $this->documentVersions($allEvents, []);
            $version = count($versions) === 1 ? array_key_first($versions) : null;
        }

        $bestSnapshot = null;
        foreach ($snapshots as $snapshot) {
            if ($version !== null && $snapshot->document->version !== $version) {
                continue;
            }

            if (!$bestSnapshot instanceof ContextSnapshot || self::snapshotTime($snapshot) > self::snapshotTime($bestSnapshot)) {
                $bestSnapshot = $snapshot;
            }
        }

        if ($bestSnapshot instanceof ContextSnapshot) {
            return $bestSnapshot->attributes;
        }

        $candidates = array_values(array_filter(
            $allEvents,
            static fn (DocumentTimelineEventRow $event): bool => $version === null || $event->documentVersion === $version
        ));
        usort($candidates, static fn (DocumentTimelineEventRow $left, DocumentTimelineEventRow $right): int => $order->compareTimelineRows($left, $right));

        $context = [];
        foreach ($candidates as $event) {
            $attributes = $event->contextSummary['attributes'] ?? null;
            if (is_array($attributes)) {
                $context = array_replace($context, $attributes);
            }
        }

        return $context;
    }

    /**
     * @param array<int, ContextSnapshot> $snapshots
     * @param array<int, DocumentTimelineEventRow> $allEvents
     * @return array<string, mixed>
     */
    private function contextForEvent(DocumentTimelineEventRow $event, array $snapshots, array $allEvents, EventTimelineOrder $order): array
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

        $context = [];
        $events = array_values(array_filter(
            $allEvents,
            static fn (DocumentTimelineEventRow $row): bool => $row->documentVersion === $event->documentVersion
                && $row->occurredAt <= $event->occurredAt
        ));
        usort($events, static fn (DocumentTimelineEventRow $left, DocumentTimelineEventRow $right): int => $order->compareTimelineRows($left, $right));
        foreach ($events as $candidate) {
            $attributes = $candidate->contextSummary['attributes'] ?? null;
            if (is_array($attributes)) {
                $context = array_replace($context, $attributes);
            }
        }

        return $context;
    }

    /**
     * @param array<int, DocumentTimelineEventRow> $events
     * @return array<int, ContextSnapshot>
     */
    private function snapshots(string $documentUuid, ProcessTemplate $template, array $events): array
    {
        if (!$this->snapshotHistoryProvider instanceof ContextSnapshotHistoryProvider) {
            return [];
        }

        $processKeys = [];
        foreach ($template->steps as $step) {
            if ($step->processKey !== null) {
                $processKeys[$step->processKey] = true;
            }
        }
        foreach ($events as $event) {
            $processKeys[$event->processKey] = true;
        }

        $snapshots = [];
        $seen = [];
        foreach (array_keys($processKeys) as $processKey) {
            foreach ($this->snapshotHistoryProvider->snapshotsForDocument($documentUuid, $processKey) as $snapshot) {
                $key = implode('|', [
                    $snapshot->processKey,
                    $snapshot->externalEventKey ?? '',
                    $snapshot->document->version,
                    self::snapshotTime($snapshot)->format(DATE_ATOM),
                ]);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $snapshots[] = $snapshot;
            }
        }

        usort($snapshots, static fn (ContextSnapshot $left, ContextSnapshot $right): int => self::snapshotTime($left) <=> self::snapshotTime($right));

        return $snapshots;
    }

    private static function snapshotTime(ContextSnapshot $snapshot): \DateTimeImmutable
    {
        return $snapshot->occurredAt ?? $snapshot->loadedAt;
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
     * @param array<int, DocumentTimelineEventRow> $events
     * @param array<int, DocumentTimelineInstanceRow> $instances
     * @return array<int, true>
     */
    private function documentVersions(array $events, array $instances): array
    {
        $versions = [];
        foreach ($events as $event) {
            $versions[$event->documentVersion] = true;
        }
        foreach ($instances as $instance) {
            $versions[$instance->documentVersion] = true;
        }

        return $versions;
    }

    /**
     * @param array<int, DocumentTimelineEventRow> $events
     * @param array<int, DocumentTimelineInstanceRow> $instances
     */
    private function hasMultipleDocumentVersions(array $events, array $instances): bool
    {
        return count($this->documentVersions($events, $instances)) > 1;
    }

    /**
     * @param array<int, JourneyTemplateStepCheckResult> $stepResults
     * @param array<int, JourneyTemplateTransitionCheckResult> $transitionResults
     */
    private function aggregateStatus(array $stepResults, array $transitionResults): string
    {
        if ($stepResults === []) {
            return self::STATUS_NOT_APPLICABLE;
        }

        foreach ($stepResults as $stepResult) {
            if ($stepResult->status === self::STEP_MISSING_REQUIRED_PROCESS) {
                return self::STATUS_DEVIATION;
            }
        }

        foreach ($transitionResults as $transitionResult) {
            if ($transitionResult->status === self::TRANSITION_WRONG_ORDER) {
                return self::STATUS_DEVIATION;
            }
        }

        foreach ($stepResults as $stepResult) {
            if ($stepResult->status === self::STEP_WARNING) {
                return self::STATUS_WARNING;
            }
        }

        foreach ($transitionResults as $transitionResult) {
            if ($transitionResult->status === self::TRANSITION_WARNING) {
                return self::STATUS_WARNING;
            }
        }

        foreach ($stepResults as $stepResult) {
            if ($stepResult->status === self::STEP_PROCESS_EXISTS) {
                return self::STATUS_SATISFIED;
            }
        }

        return self::STATUS_NOT_APPLICABLE;
    }

    private function hasDetailTemplate(?string $processKey): bool
    {
        return $processKey !== null && $this->templateProvider?->findByProcessKey($processKey) !== null;
    }
}
