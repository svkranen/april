<?php

namespace App\Wizard;

final readonly class WizardDefinitionRenderer
{
    public function __construct(
        private ?WizardLinkResolver $linkResolver = null,
        private ?WizardPrerequisiteChecker $prerequisiteChecker = null,
        private ?WizardCompletionChecker $completionChecker = null,
        private ?WizardViewFactory $viewFactory = null
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function renderLines(WizardDefinition $wizard): array
    {
        return $this->renderViewLines($this->factory()->create($wizard));
    }

    /**
     * @return array<int, string>
     */
    public function renderViewLines(WizardView $wizard): array
    {
        $lines = [
            sprintf('Name: %s', $wizard->title),
            sprintf('Key: %s', $wizard->key),
            sprintf('Version: %s', $wizard->version),
        ];

        if ($wizard->description !== null && $wizard->description !== '') {
            $lines[] = sprintf('Description: %s', $wizard->description);
        }

        $lines[] = '';
        $this->appendList($lines, 'Audience', $wizard->audience);
        $this->appendMap($lines, 'Scenario', $wizard->scenario);
        $this->appendRecords($lines, 'Prerequisites', $wizard->prerequisites);

        $lines[] = 'Steps:';
        foreach ($wizard->steps as $index => $step) {
            $lines[] = sprintf('  %d. %s (%s)', $index + 1, $step->title, $step->key);
            $this->appendOptionalValue($lines, '     Goal', $step->goal);
            $this->appendOptionalValue($lines, '     Body', $step->description);
            $this->appendList($lines, '     Concepts', $step->concepts);
            $this->appendRecords($lines, '     Links', $step->links);
            $this->appendRecords($lines, '     Prerequisites', $step->prerequisites);
            $this->appendRecords($lines, '     Completion', $step->completion);
        }

        $this->appendMap($lines, 'Completion', $wizard->completion);

        return $lines;
    }

    public function render(WizardDefinition $wizard): string
    {
        return implode(PHP_EOL, $this->renderLines($wizard)).PHP_EOL;
    }

    public function renderView(WizardView $wizard): string
    {
        return implode(PHP_EOL, $this->renderViewLines($wizard)).PHP_EOL;
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

    private function indentOf(string $label): string
    {
        return substr($label, 0, strspn($label, ' '));
    }

    private function factory(): WizardViewFactory
    {
        return $this->viewFactory ?? new WizardViewFactory(
            $this->linkResolver,
            $this->prerequisiteChecker,
            $this->completionChecker
        );
    }
}
