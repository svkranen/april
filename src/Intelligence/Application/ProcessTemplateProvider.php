<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessTemplate;

interface ProcessTemplateProvider
{
    public function findByProcessKey(string $processKey): ?ProcessTemplate;
}
