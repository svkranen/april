<?php

namespace App\Intelligence\Port;

use App\Intelligence\Domain\ProcessEventRecord;

interface ProcessEventReader
{
    /** @return iterable<ProcessEventRecord> Complete stored history, including both phases and items without UUIDs. */
    public function readForProcess(string $processKey): iterable;
}
