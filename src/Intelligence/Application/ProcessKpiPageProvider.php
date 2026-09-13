<?php

namespace App\Intelligence\Application;

use App\Intelligence\Port\ProcessKpiDefinitionProvider;
use OutOfBoundsException;

final readonly class ProcessKpiPageProvider
{
    public function __construct(
        private ProcessTemplateProvider $templates,
        private ProcessKpiDefinitionProvider $definitions,
        private ProcessKpiMeasurements $measurements,
        private ProcessKpiAggregator $aggregator
    ) {
    }

    public function build(string $key, KpiPeriod $period, ?string $version = null): ProcessKpiPage
    {
        $template = $this->templates->findByProcessKey($key);
        if ($template === null || $template->scope !== 'process') {
            throw new OutOfBoundsException('Process template not found.');
        }
        $definition = $this->definitions->forTemplate($template);
        $summary = $definition === null ? null : $this->aggregator->aggregate($template,
            $this->measurements->forTemplate($template, $definition, $version, $period->until), $period);

        return new ProcessKpiPage($template, $period, $version, $definition, $summary);
    }
}
