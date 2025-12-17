<?php

namespace App\Service\Processing;

use App\Dto\MatchingContext;
use App\Service\Amagno\MatchingLoader;
use RuntimeException;

class MatchingProvider
{
    public function __construct(
        private readonly MatchingLoader $matchingLoader,
        private readonly string $templateDir
    ) {
    }

    public function resolve(?string $profile = null, ?string $templateOverride = null): MatchingContext
    {
        $matching = $this->matchingLoader->load($profile);

        if ($templateOverride !== null) {
            $path = $this->resolveTemplatePath($templateOverride);
            if (!is_file($path)) {
                throw new RuntimeException(sprintf('Template "%s" nicht gefunden.', $templateOverride));
            }

            return new MatchingContext($matching, (string) file_get_contents($path), basename($path));
        }

        $path = $this->discoverTemplateForSystem($matching['sys'] ?? 'onprem');
        if ($path === null) {
            throw new RuntimeException('Es konnte kein Template für das Profil ermittelt werden.');
        }

        return new MatchingContext($matching, (string) file_get_contents($path), basename($path));
    }

    private function resolveTemplatePath(string $template): string
    {
        if (is_file($template)) {
            return $template;
        }

        $candidate = rtrim($this->templateDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$template;
        if (is_file($candidate)) {
            return $candidate;
        }

        return $template;
    }

    private function discoverTemplateForSystem(string $system): ?string
    {
        $files = @scandir($this->templateDir) ?: [];
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            if (str_starts_with($file, $system)) {
                return rtrim($this->templateDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$file;
            }
        }

        return null;
    }
}
