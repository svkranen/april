<?php

namespace App\Intelligence\Domain;

final readonly class ProcessTemplateSuggestionNote
{
    /**
     * @param array<int, string> $documentUuids
     */
    public function __construct(
        public string $type,
        public string $message,
        public ?string $parallelGroupKey = null,
        public array $documentUuids = [],
        public ?float $confidence = null,
        public ?string $afterStepKey = null,
        public array $observedNextSteps = [],
        public ?string $eventKey = null,
        public ?int $affectedDocuments = null,
        public ?int $minRepetitions = null,
        public ?int $maxRepetitions = null,
        public ?float $avgRepetitions = null,
        public array $previousEvents = [],
        public array $followingEvents = []
    ) {
    }
}
