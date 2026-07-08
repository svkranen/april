<?php

namespace App\Intelligence\Infrastructure\Process;

use App\Intelligence\Application\ProcessInstanceRepository;
use App\Intelligence\Domain\ProcessInstance;

final class InMemoryProcessInstanceRepository implements ProcessInstanceRepository
{
    /** @var array<string, ProcessInstance> */
    private array $instancesByIdentity = [];

    public function findByIdentity(
        string $sourceSystem,
        ?string $documentUuid,
        string $documentExternalId,
        int $documentVersion,
        string $processKey,
        string $templateVersion
    ): ?ProcessInstance {
        $identityKey = ProcessInstance::buildIdentityKey(
            $sourceSystem,
            $documentUuid,
            $documentExternalId,
            $documentVersion,
            $processKey,
            $templateVersion
        );

        return $this->instancesByIdentity[$identityKey] ?? null;
    }

    public function save(ProcessInstance $instance): ProcessInstance
    {
        $stored = $instance->id === null ? $instance->withId(count($this->instancesByIdentity) + 1) : $instance;
        $this->instancesByIdentity[$stored->identityKey()] = $stored;

        return $stored;
    }

    public function count(): int
    {
        return count($this->instancesByIdentity);
    }

    public function removeByProcessKeyAndDocumentUuid(string $processKey, string $documentUuid): int
    {
        $deleted = 0;
        foreach ($this->instancesByIdentity as $identityKey => $instance) {
            if ($instance->processKey === $processKey && $instance->documentUuid === $documentUuid) {
                unset($this->instancesByIdentity[$identityKey]);
                ++$deleted;
            }
        }

        return $deleted;
    }

    /**
     * @return array<int, ProcessInstance>
     */
    public function all(): array
    {
        return array_values($this->instancesByIdentity);
    }
}
