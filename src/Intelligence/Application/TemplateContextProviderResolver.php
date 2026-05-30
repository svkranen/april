<?php

namespace App\Intelligence\Application;

interface TemplateContextProviderResolver
{
    public function resolve(string $processKey): ?ContextProviderSelection;
}
