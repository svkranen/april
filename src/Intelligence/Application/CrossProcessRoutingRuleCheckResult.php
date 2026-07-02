<?php

namespace App\Intelligence\Application;

final readonly class CrossProcessRoutingRuleCheckResult
{
    /**
     * @param array<int, string> $messages
     */
    public function __construct(
        public string $status,
        public string $documentUuid,
        public ?int $documentVersion,
        public string $sourceProcessKey,
        public string $ruleKey,
        public string $afterStep,
        public string $expectedProcess,
        public ?\DateTimeImmutable $routingOccurredAt = null,
        public ?\DateTimeImmutable $targetStartedAt = null,
        public array $messages = []
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
            'ruleKey' => $this->ruleKey,
            'afterStep' => $this->afterStep,
            'expectedProcess' => $this->expectedProcess,
            'routingOccurredAt' => $this->routingOccurredAt?->format(DATE_ATOM),
            'targetStartedAt' => $this->targetStartedAt?->format(DATE_ATOM),
            'messages' => $this->messages,
        ];
    }
}
