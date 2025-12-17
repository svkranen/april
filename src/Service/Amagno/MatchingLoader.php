<?php

namespace App\Service\Amagno;

use RuntimeException;

class MatchingLoader
{
    public function __construct(
        private readonly string $matchingFile
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function load(?string $profile = null): array
    {
        if (!is_file($this->matchingFile)) {
            throw new RuntimeException(sprintf('Matching-Datei "%s" nicht gefunden.', $this->matchingFile));
        }

        $content = file_get_contents($this->matchingFile);
        $data = json_decode($content ?: '', true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($data) || $data === []) {
            throw new RuntimeException('Matching-Datei enthält keine Profile.');
        }

        if ($profile === null) {
            return reset($data) ?: [];
        }

        if (!isset($data[$profile])) {
            $available = implode(', ', array_keys($data));
            throw new RuntimeException(sprintf('Profil "%s" nicht vorhanden. Verfügbar: %s', $profile, $available));
        }

        return $data[$profile];
    }

    /**
     * @return list<string>
     */
    public function availableProfiles(): array
    {
        if (!is_file($this->matchingFile)) {
            return [];
        }

        $content = file_get_contents($this->matchingFile);
        $data = json_decode($content ?: '', true);

        return is_array($data) ? array_keys($data) : [];
    }
}
