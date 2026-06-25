<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\SignCheckResult;

/**
 * Read model for the on-demand Soll/Ist check on the document detail page.
 *
 * Maps ProcessTemplateCheckResult into flat, Twig-friendly fields. The result
 * is computed on demand from stored events/context snapshots and is NOT
 * persisted as a finding.
 */
final readonly class DocumentCheckResultView
{
    /**
     * @param array<int, string> $expectedSteps
     * @param array<int, string> $actualSteps
     * @param array<int, string> $deviations
     * @param array<int, string> $parallelGroupMessages
     * @param array<int, string> $warnings
     * @param array<int, array{key: string, label: ?string, status: string, satisfied: bool, requiredCount: int, actualCount: int, missingValues: array<int, string>, unexpectedValues: array<int, string>, missingContextFields: array<int, string>}> $signChecks
     * @param array<int, \App\Intelligence\Domain\ProcessDeviation> $deviationDetails structured companions for (a subset of) $deviations; carried so the process graph can attribute deviations to edges/gateways without parsing free text
     */
    public function __construct(
        public bool $available,
        public ?string $error,
        public string $status,
        public bool $isOk,
        public array $expectedSteps,
        public array $actualSteps,
        public array $deviations,
        public array $parallelGroupMessages,
        public array $warnings,
        public ?string $contextStatus,
        public array $signChecks,
        public array $deviationDetails = []
    ) {
    }

    public static function fromResult(ProcessTemplateCheckResult $result): self
    {
        $signChecks = array_map(
            static fn (SignCheckResult $signCheck): array => [
                'key' => $signCheck->key,
                'label' => $signCheck->label,
                'status' => $signCheck->status,
                'satisfied' => $signCheck->isSatisfied(),
                'requiredCount' => $signCheck->requiredCount,
                'actualCount' => $signCheck->actualCount,
                'missingValues' => $signCheck->missingValues,
                'unexpectedValues' => $signCheck->unexpectedValues,
                'missingContextFields' => $signCheck->missingContextFields,
            ],
            $result->signCheckResults
        );

        return new self(
            available: true,
            error: null,
            status: $result->status(),
            isOk: $result->isOk(),
            expectedSteps: $result->expectedSteps,
            actualSteps: $result->actualSteps,
            deviations: $result->deviations,
            parallelGroupMessages: $result->parallelGroupMessages,
            warnings: $result->contextIssues,
            contextStatus: $result->contextStatus,
            signChecks: $signChecks,
            deviationDetails: $result->deviationDetails
        );
    }

    public static function unavailable(string $error): self
    {
        return new self(
            available: false,
            error: $error,
            status: '',
            isOk: false,
            expectedSteps: [],
            actualSteps: [],
            deviations: [],
            parallelGroupMessages: [],
            warnings: [],
            contextStatus: null,
            signChecks: []
        );
    }

    public function statusCssClass(): string
    {
        return match (true) {
            $this->status === 'OK' => 'vs-ok',
            str_contains($this->status, 'DEVIATION') => 'vs-violation',
            str_contains($this->status, 'WARNING') || str_contains($this->status, 'UNCERTAIN') => 'vs-warning',
            default => 'vs-unknown',
        };
    }

    public function hasActualSteps(): bool
    {
        return $this->actualSteps !== [];
    }
}
