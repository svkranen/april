<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\IncomingEvent;
use JsonException;

final readonly class IncomingEventProcessor
{
    public function __construct(
        private IncomingEventStore $incomingEventStore,
        private EventReceiver $eventReceiver
    ) {
    }

    public function processPending(int $limit = 50, int $maxRetries = 5): IncomingEventProcessingResult
    {
        $processed = 0;
        $failed = 0;
        $dead = 0;

        foreach ($this->incomingEventStore->pending($limit, $maxRetries) as $incomingEvent) {
            $processingEvent = $this->incomingEventStore->markProcessing($incomingEvent);
            try {
                $this->eventReceiver->receive($this->payload($processingEvent), $processingEvent->rawPayload, $processingEvent->id);
                $this->incomingEventStore->markProcessed($processingEvent);
                ++$processed;
            } catch (\Throwable $exception) {
                $failedEvent = $this->incomingEventStore->markFailed($processingEvent, $exception->getMessage(), $maxRetries);
                if ($failedEvent->status === IncomingEvent::STATUS_DEAD) {
                    ++$dead;
                } else {
                    ++$failed;
                }
            }
        }

        return new IncomingEventProcessingResult($processed, $failed, $dead);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function payload(IncomingEvent $event): array
    {
        if ($event->normalizedPayloadJson !== null) {
            return $event->normalizedPayloadJson;
        }

        $decoded = json_decode($event->rawPayload, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('IncomingEvent rawPayload is not a JSON object.');
        }

        return $decoded;
    }
}
