<?php

namespace App\Intelligence\Infrastructure\Process;

use App\Intelligence\Application\ContextCoverageReport;
use App\Intelligence\Application\ContextCoverageReportBuilder;
use App\Intelligence\Application\ContextCoverageReportProvider;
use App\Intelligence\Domain\ContextSnapshot;

final class InMemoryContextCoverageReportProvider implements ContextCoverageReportProvider
{
    /**
     * @param array<int, ContextSnapshot> $snapshots
     */
    public function __construct(
        private readonly array $snapshots = [],
        private readonly ContextCoverageReportBuilder $builder = new ContextCoverageReportBuilder()
    ) {
    }

    public function build(string $processKey): ContextCoverageReport
    {
        return $this->builder->build(
            $processKey,
            array_values(array_filter(
                $this->snapshots,
                static fn (ContextSnapshot $snapshot): bool => $snapshot->processKey === $processKey
            ))
        );
    }
}
