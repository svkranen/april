<?php

namespace App\Intelligence\Domain;

use InvalidArgumentException;

final readonly class KpiEventMarker
{
    private function __construct(public string $stepKey, public string $phase)
    {
    }

    public static function at(string $stepKey, string $phase): self
    {
        if (trim($stepKey) === '' || !in_array($phase, ['before', 'after'], true)) {
            throw new InvalidArgumentException('A marker requires a step and a before/after phase.');
        }

        return new self($stepKey, $phase);
    }

    public function matches(ProcessEventRecord $event): bool
    {
        return $event->stepKey === $this->stepKey && $event->eventPhase === $this->phase;
    }
}
