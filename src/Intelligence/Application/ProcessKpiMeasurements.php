<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessKpiDefinition;
use App\Intelligence\Domain\ProcessRunMeasurement;
use App\Intelligence\Domain\ProcessRunReconstructor;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Port\ProcessEventReader;
use App\Intelligence\Domain\ProcessEventRecord;
use DateTimeImmutable;

final readonly class ProcessKpiMeasurements
{
    public function __construct(
        private ProcessEventReader $events,
        private ProcessVersionRepository $versions,
        private ProcessRunReconstructor $reconstructor = new ProcessRunReconstructor()
    ) {
    }

    /** @return list<ProcessRunMeasurement> Includes non-measurable runs and their diagnostics. */
    public function forTemplate(ProcessTemplate $template, ProcessKpiDefinition $definition, ?string $processVersion = null, ?DateTimeImmutable $observedBefore = null): array
    {
        $versions = $this->versions->findByProcessKey($template->key);
        $runs = $this->reconstructor->reconstruct($template, $definition, $this->history($template->key, $observedBefore), $versions);
        if ($processVersion === null || trim($processVersion) === '') {
            return $runs;
        }
        $selected = trim($processVersion) === 'latest'
            ? $this->versions->latestForProcess($template->key)?->version : trim($processVersion);

        return array_values(array_filter($runs, static fn (ProcessRunMeasurement $run): bool =>
            $selected !== null && $run->eligibility->processVersion?->version === $selected));
    }
    /** @return iterable<ProcessEventRecord> Preserve all earlier history; only exclude future observations. */
    private function history(string $processKey, ?DateTimeImmutable $observedBefore): iterable
    {
        foreach ($this->events->readForProcess($processKey) as $event) {
            if ($observedBefore === null || $event->occurredAt < $observedBefore) {
                yield $event;
            }
        }
    }

}
