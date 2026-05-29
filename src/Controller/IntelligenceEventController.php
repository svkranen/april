<?php

namespace App\Controller;

use App\Intelligence\Application\EventReceiver;
use App\Intelligence\Port\SignatureVerifier;
use JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

final class IntelligenceEventController
{
    public function __construct(
        private readonly SignatureVerifier $signatureVerifier,
        private readonly EventReceiver $eventReceiver
    ) {
    }

    #[Route('/api/intelligence/events', name: 'intelligence_events_receive', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $rawPayload = $request->getContent();
        $signature = $request->headers->get('X-Intelligence-Signature')
            ?? $request->headers->get('X-Amagno-Signature')
            ?? $request->headers->get('Signature')
            ?? '';

        if (!$this->signatureVerifier->verify($rawPayload, $signature)) {
            return new JsonResponse(['accepted' => false, 'error' => 'invalid_signature'], 401);
        }

        try {
            $payload = json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new JsonResponse(['accepted' => false, 'error' => 'invalid_json'], 400);
        }

        if (!is_array($payload)) {
            return new JsonResponse(['accepted' => false, 'error' => 'invalid_payload'], 400);
        }

        $result = $this->eventReceiver->receive($payload, $rawPayload);

        return new JsonResponse([
            'accepted' => true,
            'duplicate' => $result->duplicate,
            'event_id' => $result->event->id,
            'external_event_key' => $result->event->externalEventKey,
        ], $result->duplicate ? 200 : 202);
    }
}
