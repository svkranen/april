<?php

namespace App\Intelligence\Domain;

use DateTimeImmutable;

final readonly class ProcessInstance
{
    /**
     * @param array<int, string> $eventExternalKeys
     */
    public function __construct(
        public ?int $id,
        public string $sourceSystem,
        public string $processKey,
        public string $templateVersion,
        public string $documentExternalId,
        public ?string $documentUuid,
        public int $documentVersion,
        public string $status,
        public string $currentStepKey,
        public DateTimeImmutable $startedAt,
        public DateTimeImmutable $lastEventAt,
        public ?DateTimeImmutable $endedAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public array $eventExternalKeys = []
    ) {
    }

    public function withId(int $id): self
    {
        return new self(
            $id,
            $this->sourceSystem,
            $this->processKey,
            $this->templateVersion,
            $this->documentExternalId,
            $this->documentUuid,
            $this->documentVersion,
            $this->status,
            $this->currentStepKey,
            $this->startedAt,
            $this->lastEventAt,
            $this->endedAt,
            $this->createdAt,
            $this->updatedAt,
            $this->eventExternalKeys
        );
    }

    public function withEvent(ProcessEvent $event, DateTimeImmutable $updatedAt): self
    {
        $eventExternalKeys = $this->eventExternalKeys;
        if (!in_array($event->externalEventKey, $eventExternalKeys, true)) {
            $eventExternalKeys[] = $event->externalEventKey;
        }

        return new self(
            $this->id,
            $this->sourceSystem,
            $this->processKey,
            $this->templateVersion,
            $this->documentExternalId,
            $this->documentUuid,
            $this->documentVersion,
            $this->status,
            $event->eventPhase === 'after' ? $event->stepKey : $this->currentStepKey,
            $this->startedAt,
            $event->occurredAt,
            $this->endedAt,
            $this->createdAt,
            $updatedAt,
            $eventExternalKeys
        );
    }

    public function identityKey(): string
    {
        return self::buildIdentityKey(
            $this->sourceSystem,
            $this->documentUuid,
            $this->documentExternalId,
            $this->documentVersion,
            $this->processKey,
            $this->templateVersion
        );
    }

    public static function buildIdentityKey(
        string $sourceSystem,
        ?string $documentUuid,
        string $documentExternalId,
        int $documentVersion,
        string $processKey,
        string $templateVersion
    ): string {
        return implode('|', [
            $sourceSystem,
            $documentUuid !== null && $documentUuid !== '' ? 'uuid:'.$documentUuid : 'external:'.$documentExternalId,
            'version:'.$documentVersion,
            'process:'.$processKey,
            'template:'.$templateVersion,
        ]);
    }
}
