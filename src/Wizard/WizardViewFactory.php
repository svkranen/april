<?php

namespace App\Wizard;

final readonly class WizardViewFactory
{
    public function __construct(
        private ?WizardLinkResolver $linkResolver = null,
        private ?WizardPrerequisiteChecker $prerequisiteChecker = null,
        private ?WizardCompletionChecker $completionChecker = null,
        private ?WizardProgressReader $progressReader = null
    ) {
    }

    public function create(WizardDefinition $wizard): WizardView
    {
        $steps = [];
        $concepts = [];
        foreach ($wizard->steps as $step) {
            $stepView = $this->createStep($wizard->key, $step);
            $steps[] = $stepView;
            foreach ($stepView->concepts as $concept) {
                $concepts[$concept] = $concept;
            }
        }

        return new WizardView(
            $wizard->key,
            $wizard->version,
            $wizard->name,
            $wizard->description,
            $this->strings($wizard->metadata['audience'] ?? []),
            $this->mapping($wizard->metadata['scenario'] ?? []),
            array_values($concepts),
            $this->prerequisiteRecords($wizard->metadata['prerequisites'] ?? []),
            $this->progressRecord($wizard->key, null),
            $steps,
            $this->mapping($wizard->metadata['completion'] ?? [])
        );
    }

    private function createStep(string $wizardKey, WizardStepDefinition $step): WizardStepView
    {
        return new WizardStepView(
            $step->key,
            $step->title,
            $this->optionalString($step->data['body'] ?? null),
            $this->optionalString($step->data['goal'] ?? null),
            $this->strings($step->data['concepts'] ?? []),
            $this->linkRecords($step->data['links'] ?? []),
            $this->prerequisiteRecords($step->data['prerequisites'] ?? []),
            $this->progressRecord($wizardKey, $step->key),
            $this->completionRecords($step)
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

    /**
     * @return array<int, array<string, string>>
     */
    private function linkRecords(mixed $value): array
    {
        $records = $this->records($value);
        if ($this->linkResolver === null || !is_array($value)) {
            return $records;
        }

        foreach (array_values($value) as $index => $link) {
            if (!is_array($link) || !isset($records[$index])) {
                continue;
            }

            $resolved = $this->linkResolver->resolve($link);
            if ($resolved['path'] !== null) {
                $records[$index]['path'] = $resolved['path'];
            }
            if ($resolved['warning'] !== null) {
                $records[$index]['warning'] = $resolved['warning'];
            }
        }

        return $records;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function prerequisiteRecords(mixed $value): array
    {
        $records = $this->records($value);
        if ($this->prerequisiteChecker === null || !is_array($value)) {
            return $records;
        }

        foreach (array_values($value) as $index => $prerequisite) {
            if (!is_array($prerequisite) || !isset($records[$index])) {
                continue;
            }

            $result = $this->prerequisiteChecker->check($prerequisite);
            $records[$index]['status'] = $result->status;
            $records[$index]['message'] = $result->message;
        }

        return $records;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function completionRecords(WizardStepDefinition $step): array
    {
        $records = $this->records($step->data['completion'] ?? []);
        if ($records === []) {
            $mapping = $this->mapping($step->data['completion'] ?? []);
            if ($mapping !== []) {
                $records[] = $mapping;
            }
        }

        if ($this->completionChecker === null) {
            return $records;
        }

        $results = $this->completionChecker->checkStep($step);
        foreach ($results as $index => $result) {
            if (!isset($records[$index])) {
                $records[$index] = [];
            }

            $records[$index]['type'] = $result->type;
            $records[$index]['status'] = $result->status;
            $records[$index]['message'] = $result->message;
        }

        return $records;
    }

    /**
     * @return array<string, string>
     */
    private function progressRecord(string $wizardKey, ?string $stepKey): array
    {
        if ($this->progressReader === null || $wizardKey === '') {
            return [];
        }

        $state = $this->progressReader->read($wizardKey, $stepKey);
        $record = [
            'status' => $state->status,
            'message' => $state->message,
        ];

        if ($state->stepKey !== null) {
            $record['step'] = $state->stepKey;
        }

        return $record;
    }

    private function optionalString(mixed $value): ?string
    {
        if (!is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        return (string) $value;
    }
}
