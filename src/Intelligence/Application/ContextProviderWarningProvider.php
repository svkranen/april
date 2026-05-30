<?php

namespace App\Intelligence\Application;

interface ContextProviderWarningProvider
{
    /**
     * @return array<int, string>
     */
    public function warnings(): array;
}
