<?php

namespace App\Wizard;

use Symfony\Component\Routing\Exception\ExceptionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class WizardLinkResolver
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator
    ) {
    }

    /**
     * @param array<string, mixed> $link
     * @return array{path: string|null, warning: string|null}
     */
    public function resolve(array $link): array
    {
        $route = $link['route'] ?? null;
        if (!is_string($route) || trim($route) === '') {
            return [
                'path' => null,
                'warning' => 'link has no route',
            ];
        }

        try {
            return [
                'path' => $this->urlGenerator->generate($route, $this->params($link['params'] ?? [])),
                'warning' => null,
            ];
        } catch (ExceptionInterface $exception) {
            return [
                'path' => null,
                'warning' => sprintf('route "%s" could not be resolved: %s', $route, $exception->getMessage()),
            ];
        }
    }

    /**
     * @return array<string, scalar|null>
     */
    private function params(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $params = [];
        foreach ($value as $key => $item) {
            if (is_scalar($item) || $item === null) {
                $params[(string) $key] = $item;
            }
        }

        return $params;
    }
}
