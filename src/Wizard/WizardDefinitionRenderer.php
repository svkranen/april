<?php

namespace App\Wizard;

final readonly class WizardDefinitionRenderer
{
    /**
     * @return array<int, string>
     */
    public function renderLines(WizardDefinition $wizard): array
    {
        $lines = [
            sprintf('Name: %s', $wizard->name),
            sprintf('Key: %s', $wizard->key),
            sprintf('Version: %s', $wizard->version),
        ];

        if ($wizard->description !== null && $wizard->description !== '') {
            $lines[] = sprintf('Description: %s', $wizard->description);
        }

        $lines[] = '';
        $this->appendList($lines, 'Audience', $this->strings($wizard->metadata['audience'] ?? []));
        $this->appendMap($lines, 'Scenario', $this->mapping($wizard->metadata['scenario'] ?? []));
        $this->appendRecords($lines, 'Prerequisites', $this->records($wizard->metadata['prerequisites'] ?? []));

        $lines[] = 'Steps:';
        foreach ($wizard->steps as $index => $step) {
            $lines[] = sprintf('  %d. %s (%s)', $index + 1, $step->title, $step->key);
            $this->appendOptionalValue($lines, '     Goal', $step->data['goal'] ?? null);
            $this->appendOptionalValue($lines, '     Body', $step->data['body'] ?? null);
            $this->appendList($lines, '     Concepts', $this->strings($step->data['concepts'] ?? []));
            $this->appendRecords($lines, '     Links', $this->records($step->data['links'] ?? []));
            $this->appendMap($lines, '     Completion', $this->mapping($step->data['completion'] ?? []));
        }

        $this->appendMap($lines, 'Completion', $this->mapping($wizard->metadata['completion'] ?? []));

        return $lines;
    }

    public function render(WizardDefinition $wizard): string
    {
        return implode(PHP_EOL, $this->renderLines($wizard)).PHP_EOL;
    }

    /**
     * @param array<int, string> $lines
     * @param array<int, string> $values
     */
    private function appendList(array &$lines, string $label, array $values): void
    {
        if ($values === []) {
            return;
        }

        $indent = $this->indentOf($label);
        $lines[] = $label.':';
        foreach ($values as $value) {
            $lines[] = $indent.'  - '.$value;
        }
    }

    /**
     * @param array<int, string> $lines
     * @param array<string, string> $values
     */
    private function appendMap(array &$lines, string $label, array $values): void
    {
        if ($values === []) {
            return;
        }

        $indent = $this->indentOf($label);
        $lines[] = $label.':';
        foreach ($values as $key => $value) {
            $lines[] = sprintf('%s  - %s: %s', $indent, $key, $value);
        }
    }

    /**
     * @param array<int, string> $lines
     * @param array<int, array<string, string>> $records
     */
    private function appendRecords(array &$lines, string $label, array $records): void
    {
        if ($records === []) {
            return;
        }

        $indent = $this->indentOf($label);
        $lines[] = $label.':';
        foreach ($records as $record) {
            $parts = [];
            foreach ($record as $key => $value) {
                $parts[] = sprintf('%s=%s', $key, $value);
            }
            $lines[] = $indent.'  - '.implode(', ', $parts);
        }
    }

    /**
     * @param array<int, string> $lines
     */
    private function appendOptionalValue(array &$lines, string $label, mixed $value): void
    {
        if (!is_scalar($value) || trim((string) $value) === '') {
            return;
        }

        $lines[] = sprintf('%s: %s', $label, (string) $value);
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

    /**
     * @return array<int, array<string, string>>
     */
    private function records(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $records = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }

            $record = [];
            foreach ($item as $key => $fieldValue) {
                if (is_scalar($fieldValue) && trim((string) $fieldValue) !== '') {
                    $record[(string) $key] = (string) $fieldValue;
                }
            }

            if ($record !== []) {
                $records[] = $record;
            }
        }

        return $records;
    }

    private function indentOf(string $label): string
    {
        return substr($label, 0, strspn($label, ' '));
    }
}
