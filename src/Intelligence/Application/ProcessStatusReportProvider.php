<?php

namespace App\Intelligence\Application;

interface ProcessStatusReportProvider
{
    public function build(string $processKey): ProcessStatusReport;
}
