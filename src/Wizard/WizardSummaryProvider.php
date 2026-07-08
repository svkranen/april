<?php

namespace App\Wizard;

final readonly class WizardSummaryProvider
{
    public function __construct(
        private WizardDefinitionLoader $loader
    ) {
    }

    /**
     * @return array<int, WizardSummary>
     */
    public function all(): array
    {
        return array_map(
            fn (WizardDefinition $definition): WizardSummary => $this->fromDefinition($definition),
            $this->loader->all()
        );
    }

    private function fromDefinition(WizardDefinition $definition): WizardSummary
    {
        return new WizardSummary(
            $definition->key,
            $definition->name,
            $definition->description,
            $this->strings($definition->metadata['audience'] ?? []),
            $this->mapping($definition->metadata['scenario'] ?? [])
        );
    }

    /**
     * @return array<int, string>
     */
    private function strings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            if (is_scalar($item) && trim((string) $item) !== '') {
                $strings[] = (string) $item;
            }
        }

        return $strings;
    }

    /**
     * @return array<string, string>
     */
    private function mapping(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $mapping = [];
        foreach ($value as $key => $item) {
            if (is_scalar($item) && trim((string) $item) !== '') {
                $mapping[(string) $key] = (string) $item;
            }
        }

        return $mapping;
    }
}
