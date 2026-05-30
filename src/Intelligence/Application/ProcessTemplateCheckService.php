<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateArrayFactory;
use App\Intelligence\Domain\ProcessTemplateParallelGroup;
use App\Intelligence\Domain\ProcessTemplateStep;

final class ProcessTemplateCheckService
{
    public function __construct(
        private readonly DocumentTimelineProvider $timelineProvider
    ) {
    }

    /**
     * @param array<string, mixed> $templateData
     */
    public function check(
        string $documentUuid,
        string $processKey,
        array $templateData,
        ?int $documentVersion = null,
        EventTimelineOrder $order = EventTimelineOrder::DEFAULT
    ): ProcessTemplateCheckResult {
        return $this->checkDocument(
            $documentUuid,
            $processKey,
            ProcessTemplateArrayFactory::fromArray($templateData),
            $documentVersion,
            $order
        );
    }

    public function checkDocument(
        string $documentUuid,
        string $processKey,
        ProcessTemplate $template,
        ?int $documentVersion = null,
        EventTimelineOrder $order = EventTimelineOrder::DEFAULT
    ): ProcessTemplateCheckResult {
        $parallelGroups = $this->parallelGroups($template);
        $expectedSteps = $this->expectedSteps($template, $parallelGroups);
        $actualSteps = $this->actualSteps($documentUuid, $processKey, $documentVersion, $order);
        $deviations = $this->deviations($expectedSteps, $actualSteps, $parallelGroups);
        $parallelGroupMessages = $this->parallelGroupMessages($parallelGroups, $actualSteps);

        return new ProcessTemplateCheckResult($expectedSteps, $actualSteps, $deviations, $parallelGroupMessages);
    }

    /**
     * @param array<int, ProcessTemplateParallelGroup> $parallelGroups
     * @return array<int, string>
     */
    private function expectedSteps(ProcessTemplate $template, array $parallelGroups): array
    {
        $stepKeys = array_map(
            static fn (ProcessTemplateStep $step): string => $step->key,
            $template->steps
        );

        foreach ($parallelGroups as $group) {
            $missingRequiredSteps = array_values(array_filter(
                $group->requiredStepKeys,
                static fn (string $stepKey): bool => !in_array($stepKey, $stepKeys, true)
            ));
            if ($missingRequiredSteps === []) {
                continue;
            }

            $insertPosition = count($stepKeys);
            if ($group->after !== null) {
                $afterPosition = array_search($group->after, $stepKeys, true);
                if ($afterPosition !== false) {
                    $insertPosition = $afterPosition + 1;
                }
            }

            array_splice($stepKeys, $insertPosition, 0, $missingRequiredSteps);
        }

        return $stepKeys;
    }

    /**
     * @return array<int, ProcessTemplateParallelGroup>
     */
    private function parallelGroups(ProcessTemplate $template): array
    {
        $parallelGroups = [];
        foreach ($template->parallelGroups as $group) {
            if ($group->order !== 'any' || $group->requiredStepKeys === []) {
                continue;
            }

            $parallelGroups[] = $group;
        }

        return $parallelGroups;
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
     * @param array<int, ProcessTemplateParallelGroup> $parallelGroups
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

        foreach ($parallelGroups as $group) {
            $missingRequiredSteps = array_values(array_diff($group->requiredStepKeys, $actualSteps));
            if ($missingRequiredSteps !== []) {
                $deviations[] = sprintf(
                    'Parallel Group incomplete: %s (missing: %s)',
                    $group->key,
                    implode(', ', $missingRequiredSteps)
                );
            }
        }

        return $deviations;
    }

    /**
     * @param array<int, string> $expectedSteps
     * @param array<int, string> $actualSteps
     * @param array<int, ProcessTemplateParallelGroup> $parallelGroups
     */
    private function isExpectedOrder(array $expectedSteps, array $actualSteps, array $parallelGroups): bool
    {
        $stepGroups = $this->parallelStepGroups($parallelGroups);
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

                if ($this->sameAnyOrderParallelGroup($leftStepKey, $rightStepKey, $stepGroups)) {
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
     * @param array<string, string> $stepGroups
     */
    private function sameAnyOrderParallelGroup(string $leftStepKey, string $rightStepKey, array $stepGroups): bool
    {
        return isset($stepGroups[$leftStepKey], $stepGroups[$rightStepKey])
            && $stepGroups[$leftStepKey] === $stepGroups[$rightStepKey];
    }

    /**
     * @param array<int, ProcessTemplateParallelGroup> $parallelGroups
     * @return array<string, string>
     */
    private function parallelStepGroups(array $parallelGroups): array
    {
        $stepGroups = [];
        foreach ($parallelGroups as $group) {
            foreach ($group->requiredStepKeys as $stepKey) {
                $stepGroups[$stepKey] = $group->key;
            }
        }

        return $stepGroups;
    }

    /**
     * @param array<int, ProcessTemplateParallelGroup> $parallelGroups
     * @param array<int, string> $actualSteps
     * @return array<int, string>
     */
    private function parallelGroupMessages(array $parallelGroups, array $actualSteps): array
    {
        $messages = [];
        foreach ($parallelGroups as $group) {
            $missingRequiredSteps = array_values(array_diff($group->requiredStepKeys, $actualSteps));
            if ($missingRequiredSteps === []) {
                $messages[] = sprintf('Parallel Group satisfied: %s', $group->key);

                continue;
            }

            $messages[] = sprintf(
                'Parallel Group incomplete: %s (missing: %s)',
                $group->key,
                implode(', ', $missingRequiredSteps)
            );
        }

        return $messages;
    }
}
