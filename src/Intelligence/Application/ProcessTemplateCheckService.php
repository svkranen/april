<?php

namespace App\Intelligence\Application;

final class ProcessTemplateCheckService
{
    public function __construct(
        private readonly DocumentTimelineProvider $timelineProvider
    ) {
    }

    /**
     * @param array<string, mixed> $template
     */
    public function check(
        string $documentUuid,
        string $processKey,
        array $template,
        ?int $documentVersion = null,
        EventTimelineOrder $order = EventTimelineOrder::DEFAULT
    ): ProcessTemplateCheckResult {
        $expectedSteps = $this->expectedSteps($template);
        $parallelGroups = $this->parallelGroups($template);
        $actualSteps = $this->actualSteps($documentUuid, $processKey, $documentVersion, $order);
        $deviations = $this->deviations($expectedSteps, $actualSteps, $parallelGroups);

        return new ProcessTemplateCheckResult($expectedSteps, $actualSteps, $deviations);
    }

    /**
     * @param array<string, mixed> $template
     * @return array<int, string>
     */
    private function expectedSteps(array $template): array
    {
        $steps = $template['steps'] ?? [];
        if (!is_array($steps)) {
            return [];
        }

        $stepKeys = [];
        foreach ($steps as $step) {
            if (!is_array($step) || !isset($step['key'])) {
                continue;
            }

            $stepKeys[] = (string) $step['key'];
        }

        return $stepKeys;
    }

    /**
     * @param array<string, mixed> $template
     * @return array<string, string>
     */
    private function parallelGroups(array $template): array
    {
        $groups = $template['parallel_groups'] ?? [];
        if (!is_array($groups)) {
            return [];
        }

        $stepGroups = [];
        foreach ($groups as $index => $group) {
            if (!is_array($group) || ($group['order'] ?? null) !== 'any' || !isset($group['required_steps']) || !is_array($group['required_steps'])) {
                continue;
            }

            $groupKey = isset($group['key']) ? (string) $group['key'] : sprintf('parallel_group_%d', $index);
            foreach ($group['required_steps'] as $stepKey) {
                $stepGroups[(string) $stepKey] = $groupKey;
            }
        }

        return $stepGroups;
    }

    /**
     * @return array<int, string>
     */
    private function actualSteps(string $documentUuid, string $processKey, ?int $documentVersion, EventTimelineOrder $order): array
    {
        $events = array_values(array_filter(
            $this->timelineProvider->build($documentUuid, $order)->events,
            static fn (DocumentTimelineEventRow $event): bool => $event->processKey === $processKey
        ));

        if ($events === []) {
            return [];
        }

        $selectedVersion = $documentVersion ?? max(array_map(
            static fn (DocumentTimelineEventRow $event): int => $event->documentVersion,
            $events
        ));

        $events = array_values(array_filter(
            $events,
            static fn (DocumentTimelineEventRow $event): bool => $event->documentVersion === $selectedVersion
        ));

        usort($events, static fn (DocumentTimelineEventRow $left, DocumentTimelineEventRow $right): int => $order->compareTimelineRows($left, $right));

        $stepKeys = [];
        $previousStepKey = null;
        foreach ($events as $event) {
            if ($event->stepKey === $previousStepKey) {
                continue;
            }

            $stepKeys[] = $event->stepKey;
            $previousStepKey = $event->stepKey;
        }

        return $stepKeys;
    }

    /**
     * @param array<int, string> $expectedSteps
     * @param array<int, string> $actualSteps
     * @param array<string, string> $parallelGroups
     * @return array<int, string>
     */
    private function deviations(array $expectedSteps, array $actualSteps, array $parallelGroups): array
    {
        $deviations = [];

        foreach (array_values(array_diff($expectedSteps, $actualSteps)) as $stepKey) {
            $deviations[] = sprintf('Missing step: %s', $stepKey);
        }

        foreach (array_values(array_diff($actualSteps, $expectedSteps)) as $stepKey) {
            $deviations[] = sprintf('Unexpected step: %s', $stepKey);
        }

        if (!$this->isExpectedOrder($expectedSteps, $actualSteps, $parallelGroups)) {
            $deviations[] = 'Wrong order';
        }

        return $deviations;
    }

    /**
     * @param array<int, string> $expectedSteps
     * @param array<int, string> $actualSteps
     * @param array<string, string> $parallelGroups
     */
    private function isExpectedOrder(array $expectedSteps, array $actualSteps, array $parallelGroups): bool
    {
        $actualPositions = [];
        foreach ($actualSteps as $position => $stepKey) {
            $actualPositions[$stepKey] ??= $position;
        }

        for ($i = 0, $max = count($expectedSteps) - 1; $i < $max; ++$i) {
            for ($j = $i + 1, $stepsCount = count($expectedSteps); $j < $stepsCount; ++$j) {
                $leftStepKey = $expectedSteps[$i];
                $rightStepKey = $expectedSteps[$j];

                if (!isset($actualPositions[$leftStepKey], $actualPositions[$rightStepKey])) {
                    continue;
                }

                if ($this->sameAnyOrderParallelGroup($leftStepKey, $rightStepKey, $parallelGroups)) {
                    continue;
                }

                if ($actualPositions[$leftStepKey] > $actualPositions[$rightStepKey]) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param array<string, string> $parallelGroups
     */
    private function sameAnyOrderParallelGroup(string $leftStepKey, string $rightStepKey, array $parallelGroups): bool
    {
        return isset($parallelGroups[$leftStepKey], $parallelGroups[$rightStepKey])
            && $parallelGroups[$leftStepKey] === $parallelGroups[$rightStepKey];
    }
}
