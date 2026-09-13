<?php

namespace App\Intelligence\Domain;

use DateTimeImmutable;

final readonly class ProcessRunMeasurement
{
    /**
     * @param list<DocumentRef> $documents Observed document versions; never merged into an invented identity.
     * @param list<int> $processInstanceIds Technical associations, not business run identifiers.
     * @param list<StepVisitMeasurement> $stepVisits
     * @param list<string> $eventKeys
     */
    public function __construct(
        public string $key,
        public string $processKey,
        public string $templateVersion,
        public string $definitionVersion,
        public array $documents,
        public array $processInstanceIds,
        public ?DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $endedAt,
        public string $status,
        public KpiEligibilityResult $eligibility,
        public KpiDuration $e2eDuration,
        public array $stepVisits,
        public array $eventKeys
    ) {
    }
}
