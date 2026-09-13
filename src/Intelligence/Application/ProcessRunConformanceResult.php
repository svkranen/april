<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessConformanceReason;

final readonly class ProcessRunConformanceResult
{
    /** @param list<ProcessConformanceReason> $reasons */
    public function __construct(public bool $conformant, public array $reasons = [])
    {
    }
}
