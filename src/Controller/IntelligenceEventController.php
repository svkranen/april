<?php

namespace App\Controller;

use App\Intelligence\Application\IncomingEventIntake;
use App\Intelligence\Port\SignatureVerifier;
use JsonException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

final class IntelligenceEventController
{
    public function __construct(
        private readonly SignatureVerifier $signatureVerifier,
        private readonly IncomingEventIntake $incomingEventIntake,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    #[Route('/api/intelligence/events', name: 'intelligence_events_receive', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $rawPayload = $request->getContent();
        $this->debugLogRequest($request, $rawPayload);

        $signature = $request->headers->get('X-Intelligence-Secret')
            ?? $request->headers->get('X-Intelligence-Signature')
            ?? $request->headers->get('Signature')
            ?? '';

        if (!$this->signatureVerifier->verify($rawPayload, $signature)) {
            return new JsonResponse(['accepted' => false, 'error' => 'invalid_signature'], 200);
        }

        try {
            $payload = $this->payloadFromRequest($request, $rawPayload);
        } catch (JsonException) {
            return new JsonResponse(['accepted' => false, 'error' => 'invalid_json'], 200);
        }

        if ($payload === null) {
            return new JsonResponse(['accepted' => false, 'error' => 'invalid_payload'], 200);
        }

        $validationError = $this->validatePayload($payload);
        if ($validationError !== null) {
            return new JsonResponse(['accepted' => false] + $validationError, 200);
        }

        $event = $this->incomingEventIntake->accept($payload, $rawPayload, $request->headers->get('Content-Type'));

        return new JsonResponse([
            'status' => 'accepted',
            'accepted' => true,
            'duplicate' => false,
            'incoming_event_id' => $event->id,
            'external_event_key' => $event->externalEventKey,
        ], 200);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{error: string, message: string, field?: string}|null
     */
    private function validatePayload(array $payload): ?array
    {
        $processKey = $this->firstScalar($payload, ['processKey', 'process_key']);
        if ($processKey === '' || strtolower($processKey) === 'unknown') {
            return [
                'error' => 'unknown_process_key',
                'field' => 'processKey',
                'message' => 'Unknown processKey.',
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function hasScalarField(array $payload, string $field): bool
    {
        return array_key_exists($field, $payload) && is_scalar($payload[$field]);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $fields
     */
    private function firstScalar(array $payload, array $fields): string
    {
        foreach ($fields as $field) {
            if ($this->hasScalarField($payload, $field)) {
                return trim((string) $payload[$field]);
            }
        }

        return '';
    }

    private function debugLogRequest(Request $request, string $rawPayload): void
    {
        ($this->logger ?? new NullLogger())->info('Incoming intelligence event request', [
            'content_type' => $request->headers->get('Content-Type'),
            'raw_body' => $this->safeRawBody($request, $rawPayload),
            'query_parameters' => $this->safeParameters($request->query->all()),
            'request_parameters' => $this->safeParameters($request->request->all()),
            'headers' => $this->safeHeaders($request),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws JsonException
     */
    private function payloadFromRequest(Request $request, string $rawPayload): ?array
    {
        $contentType = strtolower((string) $request->headers->get('Content-Type', ''));
        $queryPayload = $request->query->all();

        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            return array_replace($queryPayload, $request->request->all());
        }

        $decoded = json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            return null;
        }

        return array_replace($queryPayload, $decoded);
    }

    private function safeRawBody(Request $request, string $rawPayload): string
    {
        if ($rawPayload === '') {
            return '';
        }

        $contentType = strtolower((string) $request->headers->get('Content-Type', ''));
        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            parse_str($rawPayload, $parameters);

            return http_build_query($this->safeParameters($parameters), '', '&', PHP_QUERY_RFC3986);
        }

        if (str_contains($contentType, 'application/json')) {
            try {
                $decoded = json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    return json_encode($this->safeParameters($decoded), JSON_THROW_ON_ERROR);
                }
            } catch (JsonException) {
                return $this->maskKnownSecretFragments($rawPayload);
            }
        }

        return $this->maskKnownSecretFragments($rawPayload);
    }

    private function maskKnownSecretFragments(string $value): string
    {
        return preg_replace(
            '/((?:apiKey|api_key|token|secret|password|signature)["\']?\s*[:=]\s*["\']?)([^"\'&\s,}]+)/i',
            '$1present',
            $value
        ) ?? $value;
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array<string, mixed>
     */
    private function safeParameters(array $parameters): array
    {
        $safe = [];
        foreach ($parameters as $name => $value) {
            $normalizedName = strtolower((string) $name);
            if (in_array($normalizedName, ['apikey', 'api_key', 'xintelligencesecret', 'x_intelligence_secret', 'x-intelligence-secret', 'token', 'secret', 'password', 'signature'], true)) {
                $safe[$name] = $value === null || $value === '' ? 'missing' : 'present';
                continue;
            }

            $safe[$name] = is_array($value) ? $this->safeParameters($value) : $value;
        }

        return $safe;
    }

    /**
     * @return array<string, mixed>
     */
    private function safeHeaders(Request $request): array
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $normalizedName = strtolower($name);
            if ($normalizedName === 'x-api-key') {
                $headers[$name] = $values === [] ? 'missing' : 'present';
                continue;
            }

            if (in_array($normalizedName, [
                'authorization',
                'cookie',
                'proxy-authorization',
                'set-cookie',
                'signature',
                'x-amagno-signature',
                'x-intelligence-secret',
                'x-intelligence-signature',
            ], true)) {
                $headers[$name] = $values === [] ? 'missing' : 'present';
                continue;
            }

            $headers[$name] = count($values) === 1 ? $values[0] : $values;
        }

        if (!array_key_exists('x-api-key', $headers)) {
            $headers['x-api-key'] = 'missing';
        }

        return $headers;
    }
}
