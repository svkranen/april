<?php

namespace App\Service\Amagno;

use RuntimeException;

class CredentialStore
{
    /**
     * @var array<int, array{host: string, user: string, password: string}>
     */
    private array $credentials = [];

    public function __construct(
        private readonly ?int $defaultCredentialId,
        ConnectionRegistry $connectionRegistry,
        ?string $defaultBaseUri = null,
        ?string $defaultUsername = null,
        ?string $defaultPassword = null
    ) {
        if (
            $this->defaultCredentialId !== null &&
            $defaultBaseUri !== null && $defaultBaseUri !== '' &&
            $defaultUsername !== null && $defaultUsername !== '' &&
            $defaultPassword !== null && $defaultPassword !== ''
        ) {
            $this->setCredentials($defaultBaseUri, $defaultUsername, $defaultPassword, $this->defaultCredentialId);
        }

        foreach ($connectionRegistry->credentialMap() as $credentialId => $data) {
            $this->setCredentials(
                $data['host'],
                $data['user'],
                $data['password'],
                (int) $credentialId
            );
        }
    }

    public function getDefaultCredentialId(): ?int
    {
        return $this->defaultCredentialId;
    }

    public function setCredentials(string $baseUri, string $username, string $password, ?int $credentialId = null): void
    {
        $id = $credentialId ?? $this->defaultCredentialId;
        if ($id === null) {
            throw new RuntimeException('Es wurde keine Credential-ID für Amagno hinterlegt.');
        }

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
