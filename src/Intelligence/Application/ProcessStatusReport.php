<?php

namespace App\Intelligence\Application;

final readonly class ProcessStatusReport
{
    /**
     * @param array<string, int> $countsByStep
     * @param array<int, ProcessStatusInstanceRow> $openInstances
     * @param array<int, ProcessStatusEventRow> $latestEvents
     */
    public function __construct(
        public string $processKey,
        public int $totalInstances,
        public array $countsByStep,
        public array $openInstances,
        public array $latestEvents
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'processKey' => $this->processKey,
            'totalInstances' => $this->totalInstances,
            'countsByStep' => $this->countsByStep,
            'openInstances' => array_map(
                static fn (ProcessStatusInstanceRow $row): array => $row->toArray(),
                $this->openInstances
            ),
            'latestEvents' => array_map(
                static fn (ProcessStatusEventRow $row): array => $row->toArray(),
                $this->latestEvents
            ),
        ];
    }
}
