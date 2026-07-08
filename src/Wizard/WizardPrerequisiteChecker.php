<?php

namespace App\Wizard;

use App\Intelligence\Application\ProcessTemplateProvider;
use Symfony\Component\Routing\Exception\ExceptionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class WizardPrerequisiteChecker
{
    public function __construct(
        private ProcessTemplateProvider $templateProvider,
        private UrlGeneratorInterface $urlGenerator
    ) {
    }

    /**
     * @param array<string, mixed> $prerequisite
     */
    public function check(array $prerequisite): WizardPrerequisiteCheckResult
    {
        $key = $this->stringValue($prerequisite['key'] ?? null, 'unknown');
        $type = $this->stringValue($prerequisite['type'] ?? null, 'unknown');

        return match ($type) {
            'process_template' => $this->checkProcessTemplate($key, $type, $prerequisite),
            'fixture_scenario' => new WizardPrerequisiteCheckResult(
                $key,
                $type,
                WizardPrerequisiteCheckResult::STATUS_WARNING,
                'Fixture scenario status cannot be verified reliably in the MVP.'
            ),
            'route' => $this->checkRoute($key, $type, $prerequisite),
            default => new WizardPrerequisiteCheckResult(
                $key,
                $type,
                WizardPrerequisiteCheckResult::STATUS_WARNING,
                sprintf('Unsupported prerequisite type "%s".', $type)
            ),
        };
    }

    /**
     * @param array<string, mixed> $prerequisite
     */
    private function checkProcessTemplate(string $key, string $type, array $prerequisite): WizardPrerequisiteCheckResult
    {
        $processKey = $this->stringValue($prerequisite['process_key'] ?? null);
        if ($processKey === '') {
            return new WizardPrerequisiteCheckResult($key, $type, WizardPrerequisiteCheckResult::STATUS_MISSING, 'Missing process_key.');
        }

        if ($this->templateProvider->findByProcessKey($processKey) === null) {
            return new WizardPrerequisiteCheckResult(
                $key,
                $type,
                WizardPrerequisiteCheckResult::STATUS_MISSING,
                sprintf('Process template "%s" was not found.', $processKey)
            );
        }

        return new WizardPrerequisiteCheckResult(
            $key,
            $type,
            WizardPrerequisiteCheckResult::STATUS_OK,
            sprintf('Process template "%s" is available.', $processKey)
        );
    }

    /**
     * @param array<string, mixed> $prerequisite
     */
    private function checkRoute(string $key, string $type, array $prerequisite): WizardPrerequisiteCheckResult
    {
        $route = $this->stringValue($prerequisite['route'] ?? null);
        if ($route === '') {
            return new WizardPrerequisiteCheckResult($key, $type, WizardPrerequisiteCheckResult::STATUS_MISSING, 'Missing route.');
        }

        try {
            $path = $this->urlGenerator->generate($route);
        } catch (ExceptionInterface $exception) {
            return new WizardPrerequisiteCheckResult(
                $key,
                $type,
                WizardPrerequisiteCheckResult::STATUS_MISSING,
                sprintf('Route "%s" could not be resolved: %s', $route, $exception->getMessage())
            );
        }

        return new WizardPrerequisiteCheckResult(
            $key,
            $type,
            WizardPrerequisiteCheckResult::STATUS_OK,
            sprintf('Route "%s" resolves to "%s".', $route, $path)
        );
    }

    private function stringValue(mixed $value, string $default = ''): string
    {
        return is_scalar($value) ? trim((string) $value) : $default;
    }
}
