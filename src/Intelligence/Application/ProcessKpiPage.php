<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessKpiDefinition;
use App\Intelligence\Domain\ProcessTemplate;

final readonly class ProcessKpiPage
{
    public function __construct(
        public ProcessTemplate $template,
        public KpiPeriod $period,
        public ?string $selectedVersion,
        public ?ProcessKpiDefinition $definition,
        public ?ProcessKpiSummary $summary
    ) {
    }
}
