<?php

namespace App\Intelligence\Application;

interface ContextCoverageReportProvider
{
    public function build(string $processKey): ContextCoverageReport;
}
