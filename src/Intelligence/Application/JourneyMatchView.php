<?php

namespace App\Intelligence\Application;

/**
 * View model for the journey match editor with live candidate preview.
 */
final readonly class JourneyMatchView
{
    /**
     * @param array<int, array{key: string, selected: bool, observed: bool}> $options sorted checkbox rows
     * @param array<int, string> $savedMatchKeys explicit match.any_process of the stored template
     */
    public function __construct(
        public string $journeyKey,
        public ?string $name,
        public string $version,
        public bool $isJourney,
        public array $options = [],
        public array $savedMatchKeys = [],
        public ?JourneyDocumentCheckReport $report = null,
        public bool $previewOverridden = false,
        public bool $saved = false,
        public ?string $errorMessage = null,
        public int $candidateLimit = JourneyMatchPreviewService::CANDIDATE_LIMIT
    ) {
    }

    public function hasCandidates(): bool
    {
        return $this->report !== null && $this->report->rows !== [];
    }

    public function limitReached(): bool
    {
        return $this->report !== null && count($this->report->rows) >= $this->candidateLimit;
    }

    /**
     * @return array<int, string> the keys currently shown in the preview
     */
    public function previewKeys(): array
    {
        return $this->report?->matchProcessKeys ?? [];
    }

    /**
     * @return array<int, string> the keys the save form would persist
     */
    public function selectedKeys(): array
    {
        return array_values(array_map(
            static fn (array $option): string => $option['key'],
            array_filter($this->options, static fn (array $option): bool => $option['selected'])
        ));
    }

    /**
     * @return array<int, string> explicit match keys of the state shown in the preview
     */
    public function explicitKeys(): array
    {
        return $this->previewOverridden ? $this->selectedKeys() : $this->savedMatchKeys;
    }

    /**
     * The preview matches via the resolver's legacy fallback (first required
     * process step) although no explicit match keys are set or selected.
     */
    public function legacyFallbackActive(): bool
    {
        return $this->report !== null && $this->explicitKeys() === [] && $this->previewKeys() !== [];
    }
}
