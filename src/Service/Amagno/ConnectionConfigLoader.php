<?php

namespace App\Service\Amagno;

use RuntimeException;

class ConnectionConfigLoader
{
    private ?array $data = null;

    public function __construct(
        private readonly string $configPath
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCredentials(): array
    {
        $data = $this->load();
        $credentials = $data['credentials'] ?? [];

        if (!is_array($credentials)) {
            throw new RuntimeException('Feld "credentials" in der Amagno-Konfiguration ist ungültig.');
        }

        return $credentials;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getConfigurations(): array
    {
        $data = $this->load();
        $config = $data['configurations'] ?? [];

        if (!is_array($config)) {
            throw new RuntimeException('Feld "configurations" in der Amagno-Konfiguration ist ungültig.');
        }

        return $config;
    }

    private function load(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        if (!is_file($this->configPath)) {
            return $this->data = [];
        }

        $contents = file_get_contents($this->configPath) ?: '';

        if (trim($contents) === '') {
            return $this->data = [];
        }

        $decoded = json_decode($contents, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(sprintf('Konfigurationsdatei "%s" enthält kein gültiges JSON: %s', $this->configPath, json_last_error_msg()));
        }

        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Konfigurationsdatei "%s" muss ein JSON-Objekt enthalten.', $this->configPath));
        }

        return $this->data = $decoded;
    }
}
