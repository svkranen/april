<?php

namespace App\Intelligence\Port;

use App\Intelligence\Domain\ProcessKpiDefinition;
use App\Intelligence\Domain\ProcessTemplate;

interface ProcessKpiDefinitionProvider
{
    public function forTemplate(ProcessTemplate $template): ?ProcessKpiDefinition;
}
