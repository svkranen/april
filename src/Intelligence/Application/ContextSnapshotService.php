<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ContextSnapshot;
use App\Intelligence\Domain\DateTimeNormalizer;
use App\Intelligence\Domain\DocumentRef;
use App\Intelligence\Domain\ProcessEventRecord;
use App\Intelligence\Port\ContextProvider;
use Psr\Log\LoggerInterface;

final class ContextSnapshotService
{
    public function __construct(
        private readonly ContextProfileProvider $profileProvider,
        private readonly ContextProvider $contextProvider,
        private readonly ContextSnapshotStore $snapshotStore,
        private readonly ?TemplateContextProviderResolver $templateContextProviderResolver = null,
        private readonly DateTimeNormalizer $dateTimeNormalizer = new DateTimeNormalizer(),
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    public function captureForEvent(ProcessEventRecord $event, ?int $incomingEventId = null): ContextSnapshotResult
    {
        $profile = $this->profileProvider->profileForProcess($event->processKey);
        $document = new DocumentRef(
            $event->sourceSystem,
            $event->documentExternalId,
            $event->documentUuid,
            $event->documentVersion
        );

        $contextProvider = $this->contextProvider;
        $requiredFields = $profile->requiredFields;
        $templateContext = $this->templateContextProviderResolver?->resolve($event->processKey);
        if ($templateContext !== null) {
            $contextProvider = $templateContext->contextProvider;
            $requiredFields = $templateContext->requiredFields;
        }
        $template = $templateContext?->template;

        $inlineAttributes = $this->inlineAttributes($event);
        $providerAttributes = $contextProvider->loadAttributes($document, $requiredFields);
        $attributes = $this->mergeInlineAttributes(
            $providerAttributes,
            $inlineAttributes,
            $requiredFields
        );
        $warnings = array_merge(
            $contextProvider instanceof ContextProviderWarningProvider ? $contextProvider->warnings() : [],
            $this->missingFieldWarnings($requiredFields, $attributes)
        );
        $eventOccurredAt = $this->dateTimeNormalizer->toUtc($event->occurredAt);
        $loadedAt = $providerAttributes === [] && $this->hasApplicableInlineAttributes($inlineAttributes, $requiredFields)
            ? $eventOccurredAt
            : $this->dateTimeNormalizer->nowUtc();
        $freshnessSeconds = $loadedAt->getTimestamp() - $eventOccurredAt->getTimestamp();
        $maxDelaySeconds = $template?->contextPolicy?->snapshotMaxDelaySeconds;
        if ($freshnessSeconds < 0) {
            $warnings[] = sprintf(
                'Context freshness is negative (%d seconds). Possible timezone skew between event occurred_at and context loaded_at.',
                $freshnessSeconds
            );
        }
        $snapshot = new ContextSnapshot(
            $document,
            $loadedAt,
            $attributes,
            $warnings,
            $event->processKey,
            $event->externalEventKey,
            $event->processInstanceId,
            $eventOccurredAt,
            $loadedAt,
            $incomingEventId ?? $event->id,
            $freshnessSeconds,
            $maxDelaySeconds === null ? null : $freshnessSeconds >= 0 && $freshnessSeconds <= $maxDelaySeconds
        );
        $this->logger?->debug('Saved snapshot attributes', [
            'process_key' => $event->processKey,
            'external_event_key' => $event->externalEventKey,
            'document_id' => $event->documentExternalId,
            'document_uuid' => $event->documentUuid,
            'attributes' => $attributes,
            'warnings' => $warnings,
        ]);

        return new ContextSnapshotResult($this->snapshotStore->save($snapshot), $warnings);
    }

    /**
     * @param array<string, mixed> $providerAttributes
     * @param array<string, mixed> $inlineAttributes
     * @param array<int, string> $requiredFields
     * @return array<string, mixed>
     */
    private function mergeInlineAttributes(array $providerAttributes, array $inlineAttributes, array $requiredFields): array
    {
        if ($inlineAttributes === []) {
            return $providerAttributes;
        }

        if ($requiredFields !== []) {
            $inlineAttributes = array_intersect_key($inlineAttributes, array_flip($requiredFields));
        }

        return array_replace($inlineAttributes, $providerAttributes);
    }

    /**
     * @param array<string, mixed> $inlineAttributes
     * @param array<int, string> $requiredFields
     */
    private function hasApplicableInlineAttributes(array $inlineAttributes, array $requiredFields): bool
    {
        if ($inlineAttributes === []) {
            return false;
        }

        if ($requiredFields === []) {
            return true;
        }

        return array_intersect_key($inlineAttributes, array_flip($requiredFields)) !== [];
    }

    /**
     * @return array<string, mixed>
     */
    private function inlineAttributes(ProcessEventRecord $event): array
    {
        foreach ([$event->normalizedEventJson, $event->rawPayloadJson] as $json) {
            $decoded = json_decode($json, true);
            if (!is_array($decoded)) {
                continue;
            }

            foreach (['attributes', 'context'] as $key) {
                if (is_array($decoded[$key] ?? null)) {
                    return $decoded[$key];
                }
            }
        }

        return [];
    }

    /**
     * @param array<int, string> $requiredFields
     * @param array<string, mixed> $attributes
     * @return array<int, string>
     */
    private function missingFieldWarnings(array $requiredFields, array $attributes): array
    {
        $warnings = [];
        foreach ($requiredFields as $field) {
            if (!array_key_exists($field, $attributes) || $attributes[$field] === null || $attributes[$field] === '') {
                $warnings[] = sprintf('Missing required context field "%s".', $field);
            }
        }

        return $warnings;
    }
}
