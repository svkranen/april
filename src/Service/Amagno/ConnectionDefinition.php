<?php

namespace App\Service\Amagno;

use InvalidArgumentException;

class ConnectionDefinition
{
    public function __construct(
        private readonly string $id,
        private readonly int $credentialId,
        private readonly string $baseUri,
        private readonly string $username,
        private readonly string $password,
        private readonly ?string $authType,
        private readonly string $vaultId,
        private readonly string $magnetId,
        private readonly ?string $profile,
        private readonly ?string $template,
        private readonly ?string $system,
        private readonly ?string $exportTarget,
        private readonly ?string $successStampId,
        private readonly ?string $errorStampId,
        private readonly ?string $errorAttributeId
    ) {
        if ($this->id === '') {
            throw new InvalidArgumentException('Connection id darf nicht leer sein.');
        }
        if ($this->vaultId === '' || $this->magnetId === '') {
            throw new InvalidArgumentException('VaultId und MagnetId dürfen nicht leer sein.');
        }
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $credential
     */
    public static function fromArray(array $config, array $credential): self
    {
        foreach (['id', 'credential_id', 'vault_id', 'magnet_id'] as $key) {
            if (!isset($config[$key]) || $config[$key] === '') {
                throw new InvalidArgumentException(sprintf('Feld "%s" fehlt in der Connection-Konfiguration.', $key));
            }
        }

        foreach (['cid', 'base_uri', 'username', 'password'] as $key) {
            if (!isset($credential[$key]) || $credential[$key] === '') {
                throw new InvalidArgumentException(sprintf('Feld "%s" fehlt in der Credential-Konfiguration.', $key));
            }
        }

        if ((int) $config['credential_id'] !== (int) $credential['cid']) {
            throw new InvalidArgumentException(sprintf(
                'Credential-ID %s nicht in Liste der Credentials vorhanden.',
                $config['credential_id']
            ));
        }

        return new self(
            id: (string) $config['id'],
            credentialId: (int) $config['credential_id'],
            baseUri: (string) $credential['base_uri'],
            username: (string) $credential['username'],
            password: (string) $credential['password'],
            authType: isset($credential['auth_type']) && $credential['auth_type'] !== '' ? (string) $credential['auth_type'] : null,
            vaultId: (string) $config['vault_id'],
            magnetId: (string) $config['magnet_id'],
            profile: $config['profile'] ?? null,
            template: $config['template'] ?? null,
            system: $config['system'] ?? null,
            exportTarget: $config['export'] ?? null,
            successStampId: $config['success_stamp'] ?? null,
            errorStampId: $config['error_stamp'] ?? null,
            errorAttributeId: $config['error_attribute'] ?? null
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function credentialId(): int
    {
        return $this->credentialId;
    }

    public function baseUri(): string
    {
        return $this->baseUri;
    }

    public function username(): string
    {
        return $this->username;
    }

    public function password(): string
    {
        return $this->password;
    }

    public function authType(): ?string
    {
        return $this->authType;
    }

    public function vaultId(): string
    {
        return $this->vaultId;
    }

    public function magnetId(): string
    {
        return $this->magnetId;
    }

    public function profile(): ?string
    {
        return $this->profile;
    }

    public function template(): ?string
    {
        return $this->template;
    }

    public function system(): ?string
    {
        return $this->system;
    }

    public function exportTarget(): ?string
    {
        return $this->exportTarget;
    }

    public function successStampId(): ?string
    {
        return $this->successStampId;
    }

    public function errorStampId(): ?string
    {
        return $this->errorStampId;
    }

    public function errorAttributeId(): ?string
    {
        return $this->errorAttributeId;
    }
}
