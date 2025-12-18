<?php

namespace App\Service\Amagno;

class CredentialStore
{
    /**
     * @var array<int, array{host: string, user: string, password: string}>
     */
    private array $credentials = [];

    public function __construct(
        private readonly int $credentialId,
        ?string $defaultBaseUri = null,
        ?string $defaultUsername = null,
        ?string $defaultPassword = null
    ) {
        if (
            $defaultBaseUri !== null && $defaultBaseUri !== '' &&
            $defaultUsername !== null && $defaultUsername !== '' &&
            $defaultPassword !== null && $defaultPassword !== ''
        ) {
            $this->setCredentials($defaultBaseUri, $defaultUsername, $defaultPassword);
        }
    }

    public function getCredentialId(): int
    {
        return $this->credentialId;
    }

    public function setCredentials(string $baseUri, string $username, string $password, ?int $credentialId = null): void
    {
        $id = $credentialId ?? $this->credentialId;
        $this->credentials[$id] = [
            'host' => $this->normalizeHost($baseUri),
            'user' => $username,
            'password' => $password,
        ];
    }

    public function getCredentials(int $credentialId): ?array
    {
        return $this->credentials[$credentialId] ?? null;
    }

    private function normalizeHost(string $baseUri): string
    {
        $base = rtrim($baseUri, '/');
        if (!str_ends_with($base, '/api/v2')) {
            $base .= '/api/v2';
        }

        return $base;
    }
}
