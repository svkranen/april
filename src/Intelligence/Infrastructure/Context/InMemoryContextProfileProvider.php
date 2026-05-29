<?php

namespace App\Intelligence\Infrastructure\Context;

use App\Intelligence\Application\ContextProfileProvider;
use App\Intelligence\Domain\ContextProfile;

final class InMemoryContextProfileProvider implements ContextProfileProvider
{
    /**
     * @param array<string, array<int, string>> $profiles
     */
    public function __construct(
        private readonly array $profiles = []
    ) {
    }

    public function profileForProcess(string $processKey): ContextProfile
    {
        return new ContextProfile($processKey, array_values($this->profiles[$processKey] ?? []));
    }
}
