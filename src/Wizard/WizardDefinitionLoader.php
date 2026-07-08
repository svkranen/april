<?php

namespace App\Wizard;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final readonly class WizardDefinitionLoader
{
    public function __construct(
        private string $wizardDirectory
    ) {
    }

    /**
     * @return array<int, WizardDefinition>
     */
    public function all(): array
    {
        $paths = glob(rtrim($this->wizardDirectory, '/').'/*.yaml') ?: [];
        sort($paths);

        return array_map(fn (string $path): WizardDefinition => $this->loadFile($path), $paths);
    }

    public function load(string $key): WizardDefinition
    {
        $key = trim($key);
        if ($key === '') {
            throw new WizardDefinitionException('Wizard key must not be empty.');
        }

        return $this->loadFile(rtrim($this->wizardDirectory, '/').'/'.$key.'.yaml');
    }

    public function loadFile(string $path): WizardDefinition
    {
        if (!is_file($path)) {
            throw new WizardDefinitionException(sprintf('Wizard definition file "%s" was not found.', $path));
        }

        try {
            $data = Yaml::parseFile($path);
        } catch (ParseException $exception) {
            throw new WizardDefinitionException(sprintf('Invalid wizard YAML in "%s": %s', $path, $exception->getMessage()), 0, $exception);
        }

        if (!is_array($data)) {
            throw new WizardDefinitionException(sprintf('Wizard definition "%s" must be a YAML mapping.', $path));
        }

        return $this->fromArray($data, $path);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function fromArray(array $data, string $path): WizardDefinition
    {
        $key = $this->requiredString($data, 'key', $path);
        $version = $this->requiredString($data, 'version', $path);
        $name = $this->requiredString($data, 'name', $path);
        $stepsData = $data['steps'] ?? null;

        if (!is_array($stepsData) || $stepsData === []) {
            throw new WizardDefinitionException(sprintf('Wizard definition "%s" must define at least one step.', $path));
        }

        $steps = [];
        foreach (array_values($stepsData) as $index => $stepData) {
            if (!is_array($stepData)) {
                throw new WizardDefinitionException(sprintf('Wizard definition "%s" step %d must be a mapping.', $path, $index + 1));
            }

            $steps[] = new WizardStepDefinition(
                $this->requiredString($stepData, 'key', $path, sprintf('step %d', $index + 1)),
                $this->requiredString($stepData, 'title', $path, sprintf('step "%s"', (string) ($stepData['key'] ?? $index + 1))),
                $stepData
            );
        }

        return new WizardDefinition(
            $key,
            $version,
            $name,
            $steps,
            $path,
            is_string($data['description'] ?? null) ? $data['description'] : null,
            $data
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requiredString(array $data, string $field, string $path, ?string $scope = null): string
    {
        $value = $data[$field] ?? null;
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            $label = $scope === null ? 'Wizard definition' : 'Wizard definition '.$scope;

            throw new WizardDefinitionException(sprintf('%s "%s" is missing required field "%s".', $label, $path, $field));
        }

        $value = trim((string) $value);
        if ($value === '') {
            $label = $scope === null ? 'Wizard definition' : 'Wizard definition '.$scope;

            throw new WizardDefinitionException(sprintf('%s "%s" field "%s" must not be empty.', $label, $path, $field));
        }

        return $value;
    }
}
