<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ContextProfile;

interface ContextProfileProvider
{
    public function profileForProcess(string $processKey): ContextProfile;
}
