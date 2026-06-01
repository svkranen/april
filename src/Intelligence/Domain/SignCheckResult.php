<?php

namespace App\Intelligence\Domain;

final readonly class SignCheckResult
{
    public const STATUS_SATISFIED = 'satisfied';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_MISSING_ALL = 'missing_all';
    public const STATUS_MISSING_CONTEXT = 'missing_context';
    public const STATUS_EMPTY_REQUIRED_SET = 'empty_required_set';
    public const STATUS_UNEXPECTED_SIGNER = 'unexpected_signer';

    /**
     * @param array<int, string> $missingContextFields
     * @param array<int, string> $missingValues
     * @param array<int, string> $unexpectedValues
     */
    public function __construct(
        public string $key,
        public ?string $label,
        public string $status,
        public int $requiredCount,
        public int $actualCount,
        public int $matchedCount,
        public int $missingCount,
        public int $unexpectedCount,
        public array $missingContextFields = [],
        public array $missingValues = [],
        public array $unexpectedValues = []
    ) {
    }

    public function isSatisfied(): bool
    {
        return $this->status === self::STATUS_SATISFIED
            || $this->status === self::STATUS_UNEXPECTED_SIGNER;
    }
}
