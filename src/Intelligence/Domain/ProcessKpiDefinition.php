<?php

namespace App\Intelligence\Domain;

use InvalidArgumentException;

final readonly class ProcessKpiDefinition
{
    /** @param list<list<KpiEventMarker>> $completionGroups */
    private function __construct(
        public string $templateKey,
        public string $templateVersion,
        public string $version,
        public KpiEventMarker $start,
        public array $completionGroups
    ) {
    }

    /**
     * Any complete group ends the run; every marker within that group is required.
     * @param list<list<KpiEventMarker>> $completionGroups
     */
    public static function forTemplate(ProcessTemplate $template, string $version, KpiEventMarker $start, array $completionGroups): self
    {
        if (trim($version) === '' || $completionGroups === [] || $template->scope !== 'process') {
            throw new InvalidArgumentException('A process measurement definition requires a version and completion markers.');
        }
        $steps = array_map(static fn (ProcessTemplateStep $step): string => $step->key, $template->steps);
        if (!in_array($start->stepKey, $steps, true)) {
            throw new InvalidArgumentException('The start marker must reference a template step.');
        }
        foreach ($completionGroups as $group) {
            if ($group === []) {
                throw new InvalidArgumentException('Completion groups must not be empty.');
            }
            $seen = [];
            foreach ($group as $marker) {
                if (!in_array($marker->stepKey, $steps, true)
                    || ($marker->stepKey === $start->stepKey && $marker->phase === $start->phase)
                    || isset($seen[$marker->stepKey][$marker->phase])) {
                    throw new InvalidArgumentException('Completion markers must be distinct template markers other than the start.');
                }
                $seen[$marker->stepKey][$marker->phase] = true;
            }
            foreach ($template->parallelGroups as $parallel) {
                $branchMarkers = array_intersect(array_keys($seen), $parallel->requiredStepKeys);
                if ($branchMarkers !== [] && array_diff($parallel->requiredStepKeys, array_keys($seen)) !== []) {
                    throw new InvalidArgumentException('A parallel completion must include every required branch or use a separate process completion marker.');
                }
            }
        }

        return new self($template->key, $template->version, $version, $start, $completionGroups);
    }
}
