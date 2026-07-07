<?php

namespace App\Service\Settings;

class SettingsProfileProvider
{
    public function __construct(
        private readonly string $matchingFile
    ) {
    }

    /**
     * @return array<int, array{name: string, mapping: array<string, mixed>}>
     */
    public function profiles(): array
    {
        $profiles = $this->loadProfiles();
        $result = [];

        foreach ($profiles as $name => $mapping) {
            if (!is_array($mapping)) {
                continue;
            }

            $result[] = [
                'name' => (string) $name,
                'mapping' => $mapping,
            ];
        }

        return $result;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function mappingsByName(): array
    {
        $profiles = [];

        foreach ($this->loadProfiles() as $name => $mapping) {
            if (is_array($mapping)) {
                $profiles[(string) $name] = $mapping;
            }
        }

        return $profiles;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadProfiles(): array
    {
        if (!is_file($this->matchingFile)) {
            return [];
        }

        $content = file_get_contents($this->matchingFile);
        if ($content === false || trim($content) === '') {
            return [];
        }

        try {
            $profiles = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($profiles) ? $profiles : [];
    }
}
