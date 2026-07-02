<?php

namespace App\Intelligence\Application;

final readonly class CrossProcessRoutingCheckResult
{
    /**
     * @param array<int, CrossProcessRoutingRuleCheckResult> $ruleResults
     */
    public function __construct(
        public string $status,
        public string $documentUuid,
        public ?int $documentVersion,
        public string $sourceProcessKey,
        public array $ruleResults
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'documentUuid' => $this->documentUuid,
            'documentVersion' => $this->documentVersion,
            'sourceProcessKey' => $this->sourceProcessKey,
            'ruleResults' => array_map(
                static fn (CrossProcessRoutingRuleCheckResult $result): array => $result->toArray(),
                $this->ruleResults
            ),
        ];
    }
}
