<?php

namespace App\Intelligence\Infrastructure\Process;

use App\Intelligence\Application\ProcessVersionRepository;
use App\Intelligence\Domain\ProcessVersion;

final class InMemoryProcessVersionRepository implements ProcessVersionRepository
{
    /**
     * @param array<int, ProcessVersion> $versions
     */
    public function __construct(
        private array $versions = []
    ) {
    }

    public function findByProcessKey(string $processKey): array
    {
        $versions = array_values(array_filter(
            $this->versions,
            static fn (ProcessVersion $version): bool => $version->processKey === $processKey
        ));
        usort($versions, static fn (ProcessVersion $left, ProcessVersion $right): int => $left->validFrom <=> $right->validFrom);

        return $versions;
    }

    public function findOneByProcessKeyAndVersion(string $processKey, string $version): ?ProcessVersion
    {
        foreach ($this->versions as $processVersion) {
            if ($processVersion->processKey === $processKey && $processVersion->version === $version) {
                return $processVersion;
            }
        }

        return null;
    }

    public function latestForProcess(string $processKey): ?ProcessVersion
    {
        $versions = $this->findByProcessKey($processKey);

        return $versions === [] ? null : $versions[count($versions) - 1];
    }

    public function save(ProcessVersion $processVersion): ProcessVersion
    {
        $this->versions[] = $processVersion;

        return $processVersion;
    }
}
