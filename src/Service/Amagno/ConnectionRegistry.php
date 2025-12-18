<?php

namespace App\Service\Amagno;

use RuntimeException;

class ConnectionRegistry
{
    /**
     * @var array<string, ConnectionDefinition>
     */
    private array $connections = [];

    /**
     * @var array<int, array{host: string, user: string, password: string}>
     */
    private array $credentials = [];

    public function __construct(ConnectionConfigLoader $configLoader)
    {
        $credentials = [];
        foreach ($configLoader->getCredentials() as $credential) {
            if (!isset($credential['cid'])) {
                throw new RuntimeException('Credential-Eintrag ohne "cid" gefunden.');
            }

            $credentials[(int) $credential['cid']] = $credential;
        }

        $this->credentials = array_map(
            fn (array $credential) => [
                'host' => $credential['base_uri'],
                'user' => $credential['username'],
                'password' => $credential['password'],
            ],
            $credentials
        );

        foreach ($configLoader->getConfigurations() as $config) {
            $credentialId = (int) ($config['credential_id'] ?? 0);
            if (!isset($credentials[$credentialId])) {
                throw new RuntimeException(sprintf(
                    'Connection "%s" verweist auf unbekannte Credential-ID %d.',
                    $config['id'] ?? 'n/a',
                    $credentialId
                ));
            }
            $definition = ConnectionDefinition::fromArray($config, $credentials[$credentialId]);
            $this->connections[$definition->id()] = $definition;
        }
    }

    public function hasConnections(): bool
    {
        return $this->connections !== [];
    }

    /**
     * @return ConnectionDefinition[]
     */
    public function all(): array
    {
        return array_values($this->connections);
    }

    public function get(string $id): ConnectionDefinition
    {
        if (!isset($this->connections[$id])) {
            throw new RuntimeException(sprintf('Keine Connection-Konfiguration für ID "%s" vorhanden.', $id));
        }

        return $this->connections[$id];
    }

    /**
     * @return array<int, array{host: string, user: string, password: string}>
     */
    public function credentialMap(): array
    {
        return $this->credentials;
    }
}
