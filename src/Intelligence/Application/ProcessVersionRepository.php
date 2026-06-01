<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessVersion;

interface ProcessVersionRepository
{
    /**
     * @return array<int, ProcessVersion>
     */
    public function findByProcessKey(string $processKey): array;

    public function findOneByProcessKeyAndVersion(string $processKey, string $version): ?ProcessVersion;

    public function latestForProcess(string $processKey): ?ProcessVersion;

    public function save(ProcessVersion $processVersion): ProcessVersion;
}
