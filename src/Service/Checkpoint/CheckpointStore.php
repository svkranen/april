<?php

namespace App\Service\Checkpoint;

class CheckpointStore
{
    public function __construct(
        private readonly string $directory
    ) {
    }

    public function read(string $key): ?array
    {
        $path = $this->pathFor($key);
        if (!is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    public function write(string $key, array $data): void
    {
        $path = $this->pathFor($key);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function pathFor(string $key): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $key);

        return rtrim($this->directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $safe . '.json';
    }
}
