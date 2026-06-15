<?php

namespace App\Intelligence\Infrastructure\Access;

use App\Intelligence\Application\VisibilityCheckEvaluationResult;
use App\Intelligence\Application\VisibilityCheckResultProvider;
use App\Intelligence\Application\VisibilityCheckResultRecord;
use App\Intelligence\Application\VisibilityCheckResultSaveContext;
use App\Intelligence\Application\VisibilityCheckResultStore;
use DateTimeImmutable;

final class InMemoryVisibilityCheckResultStore implements VisibilityCheckResultStore, VisibilityCheckResultProvider
{
    /** @var array<int, VisibilityCheckResultRecord> */
    private array $records = [];

    public function save(VisibilityCheckEvaluationResult $result, ?VisibilityCheckResultSaveContext $context = null): void
    {
        $context ??= new VisibilityCheckResultSaveContext();
        $this->records[] = new VisibilityCheckResultRecord(
            count($this->records) + 1,
            $result->documentUuid,
            $context->documentVersion,
            $result->processKey,
            $context->sourceSystem ?? 'unknown',
            $result->stepKey,
            $result->eventPhase,
            $result->checkKey,
            $result->profileKey === '' ? null : $result->profileKey,
            $result->probeKey,
            $context->probeType,
            $context->probeRef,
            $result->expected,
            $result->actual,
            $result->status,
            $result->reason,
            new DateTimeImmutable(),
            $context->attemptNo ?? 1,
            $context->isFinal,
            $this->documentCount($result),
            $context->details ?? $result->details
        );
    }

    public function saveMany(array $results, ?VisibilityCheckResultSaveContext $context = null): int
    {
        foreach ($results as $result) {
            $this->save($result, $context);
        }

        return count($results);
    }

    public function findByDocument(string $documentUuid, ?string $processKey = null): array
    {
        return array_values(array_filter(
            $this->records,
            static fn (VisibilityCheckResultRecord $record): bool => $record->documentUuid === $documentUuid
                && ($processKey === null || $record->processKey === $processKey)
        ));
    }

    private function documentCount(VisibilityCheckEvaluationResult $result): ?int
    {
        $documentCount = $result->details['documentCount'] ?? null;

        return is_int($documentCount) ? $documentCount : null;
    }
}
