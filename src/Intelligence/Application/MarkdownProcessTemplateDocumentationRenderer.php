<?php

namespace App\Intelligence\Application;

final class MarkdownProcessTemplateDocumentationRenderer implements ProcessTemplateDocumentationRendererInterface
{
    public function render(ProcessTemplateDocumentation $doc): string
    {
        $lines = [
            '# '.($doc->title ?? $doc->processKey), '',
            '## Metadaten', '',
            '| Feld | Wert |', '| --- | --- |',
            $this->row(['processKey', $doc->processKey]),
            $this->row(['version', $doc->version]),
            $this->row(['sourceSystem', $doc->sourceSystem]),
            $this->row(['Template-Dateipfad', $doc->templatePath]),
            $this->row(['Generiert am', $doc->generatedAt]), '',
            '## Management Summary', '',
            '| Kennzahl | Wert |', '| --- | --- |',
        ];
        foreach ($doc->summary as $label => $value) {
            $lines[] = $this->row([$label, (string) $value]);
        }
        $lines = array_merge($lines, ['', '> Das YAML-Template ist die Source of Truth.', '', '## Prozessschritte', '',
            '> before/after sind Kontrollphasen am gleichen stepKey und keine eigenen Prozessschritte.', '']);
        foreach ($doc->steps as $step) {
            $lines[] = '### `'.$this->md((string) $step['key']).'` — '.$this->md((string) $step['label']);
            $lines[] = '';
            $lines[] = sprintf('- Typ: `%s`', $this->md((string) $step['type']));
            $lines[] = sprintf('- Erforderlich: %s', $step['required'] ? 'ja' : 'nein');
            $lines[] = '- before-Kontrollphasen: '.$this->checks($step['beforeChecks']);
            $lines[] = '- after-Kontrollphasen: '.$this->checks($step['afterChecks']);
            $lines[] = '';
        }

        $lines = array_merge($lines, ['## Soll-Prozess / Übergänge', '']);
        if ($doc->transitions === []) {
            $lines[] = 'Keine expliziten Übergänge definiert; die Reihenfolge der Steps dient als Dokumentationshinweis.';
        } else {
            $lines[] = '| Von | Zieltyp | Ziel |';
            $lines[] = '| --- | --- | --- |';
            foreach ($doc->transitions as $transition) {
                $parallel = $transition['toParallelGroup'] !== null;
                $lines[] = $this->row([(string) $transition['from'], $parallel ? 'Parallelgruppe' : 'Step', (string) ($transition['to'] ?? $transition['toParallelGroup'])]);
            }
        }
        $lines = array_merge($lines, ['', '### Parallelgruppen', '']);
        foreach ($doc->parallelGroups as $group) {
            $lines[] = sprintf('- `%s`: nach `%s`; Pflichtschritte: %s; Reihenfolge: `%s`; weiter: `%s`', $this->md($group['key']), $this->md($group['after'] ?? '-'), $this->inline($group['requiredSteps']), $this->md($group['order']), $this->md($group['next'] ?? '-'));
        }
        if ($doc->parallelGroups === []) {
            $lines[] = 'Keine Parallelgruppen definiert.';
        }

        $lines = array_merge($lines, ['', '## Decision Points', '']);
        foreach ($doc->decisionPoints as $point) {
            $lines[] = sprintf('### `%s`', $this->md($point['key']));
            $lines[] = '';
            $lines[] = sprintf('- Nach Step: `%s`', $this->md($point['after'] ?? '-'));
            $lines[] = '- Benötigte Felder: '.$this->inline($point['requiredFields']);
            foreach ($point['rules'] as $rule) {
                $condition = $rule['else'] ? 'Sonst' : sprintf('Wenn `%s` `%s` %s', $rule['field'], $rule['operator'], $this->scalar($rule['value']));
                $lines[] = sprintf('- %s, dann %s `%s`.', $condition, $rule['targetType'], $this->md($rule['target'] ?? '-'));
            }
            $lines[] = '';
        }
        if ($doc->decisionPoints === []) {
            $lines[] = 'Keine Decision Points definiert.';
            $lines[] = '';
        }

        $lines = array_merge($lines, $this->context($doc), $this->signChecks($doc), $this->access($doc));
        $lines[] = '## Warnungen';
        $lines[] = '';
        foreach ($doc->warnings ?: ['Keine strukturellen Mapping-Warnungen.'] as $warning) {
            $lines[] = '- '.$this->md($warning);
        }
        $lines[] = '';
        $lines[] = '## Grenzen / Annahmen';
        $lines[] = '';
        foreach ($doc->assumptions as $assumption) {
            $lines[] = '- '.$this->md($assumption);
        }
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function context(ProcessTemplateDocumentation $doc): array
    {
        $lines = ['## Context / Field Mapping', '', '- context_profile.required: '.$this->inline($doc->requiredContextFields), ''];
        if ($doc->fieldMappings !== []) {
            $lines = array_merge($lines, ['| Feld | Quelle | tag_id | tag_name | value_type | stability |', '| --- | --- | --- | --- | --- | --- |']);
            foreach ($doc->fieldMappings as $mapping) {
                $lines[] = $this->row(array_map(static fn ($value): string => (string) ($value ?? '-'), array_values($mapping)));
            }
        } else {
            $lines[] = 'Keine Field Mappings definiert.';
        }
        $lines[] = '';
        $lines[] = '### Context Policy';
        $lines[] = '';
        $lines[] = $doc->contextPolicy === [] ? 'Keine Context Policy definiert.' : sprintf('- Snapshot max. Verzögerung: `%s` Sekunden\n- Verhalten bei veraltetem Snapshot: `%s`', $doc->contextPolicy['snapshotMaxDelaySeconds'] ?? '-', $doc->contextPolicy['snapshotStaleBehavior'] ?? '-');
        $lines[] = '';

        return $lines;
    }

    private function signChecks(ProcessTemplateDocumentation $doc): array
    {
        $lines = ['## SignChecks', ''];
        if ($doc->signChecks === []) {
            return array_merge($lines, ['Keine SignChecks definiert.', '']);
        }
        $lines = array_merge($lines, ['| Key | Label | Soll-Feld | Ist-Feld | Operator |', '| --- | --- | --- | --- | --- |']);
        foreach ($doc->signChecks as $check) {
            $lines[] = $this->row(array_values($check));
        }

        return array_merge($lines, ['']);
    }

    private function access(ProcessTemplateDocumentation $doc): array
    {
        $access = $doc->access;
        $lines = ['## Access-/Visibility Summary', '', '> APRIL dokumentiert definierte Probes und Kontrollen, ist aber keine vollständige ACL-Engine.', '', '### Coverage Summary', '', '| Kennzahl | Wert |', '| --- | --- |'];
        foreach ($access['coverage'] as $key => $value) {
            $lines[] = $this->row([$key, (string) $value]);
        }
        $sections = [
            'Access-Probes' => ['probes', ['key', 'sourceSystem', 'type', 'description', 'maxDocuments']],
            'Visibility Profiles' => ['profiles', ['key', 'visible', 'notVisible']],
            'Visibility Profile Resolver' => ['resolvers', ['key', 'field', 'map']],
            'Retry Policies' => ['retryPolicies', ['key', 'attemptsAfterSeconds', 'forbiddenFound', 'expectedMissing', 'probeTooLarge']],
            'Step-nahe Visibility Checks' => ['checks', ['stepKey', 'phase', 'checkKey', 'expectedProfile', 'expectedProfileResolver', 'retryPolicy', 'coverage']],
        ];
        foreach ($sections as $title => [$key, $columns]) {
            $lines = array_merge($lines, ['', '### '.$title, '']);
            $rows = $access[$key];
            if ($rows === []) {
                $lines[] = 'Keine Einträge.';
                continue;
            }
            $lines[] = '| '.implode(' | ', $columns).' |';
            $lines[] = '| '.implode(' | ', array_fill(0, count($columns), '---')).' |';
            foreach ($rows as $row) {
                $lines[] = $this->row(array_map(fn ($column): string => $this->display($row[$column] ?? null), $columns));
            }
        }
        $lines = array_merge($lines, ['', '### Manual Access Tests', '']);
        foreach ($access['manualTests'] as $test) {
            $lines[] = sprintf('#### `%s` — %s', $this->md($test['key']), $this->md($test['title'] ?? $test['key']));
            $lines[] = '';
            $lines[] = '- Beschreibung: '.$this->md($test['description'] ?? '-');
            $lines[] = '- Frequenz: '.$this->md($test['frequency'] ?? '-');
            $lines[] = '- Evidenz: '.$this->md($test['evidenceRequired'] ?? '-');
            $lines[] = '- Test Procedure: '.$this->inline($test['testProcedure']);
            $lines[] = '- Expected Result: '.$this->inline($test['expectedResult']);
            $lines[] = '';
        }
        if ($access['manualTests'] === []) {
            $lines[] = 'Keine manuellen Access Tests definiert.';
            $lines[] = '';
        }

        return $lines;
    }

    private function checks(array $checks): string
    {
        return $checks === [] ? '-' : implode('; ', array_map(fn ($check): string => sprintf('`%s` (Profil: `%s`, Resolver: `%s`, Retry: `%s`)', $this->md($check['key']), $this->md($check['expectedProfile'] ?? '-'), $this->md($check['expectedProfileResolver'] ?? '-'), $this->md($check['retryPolicy'] ?? '-')), $checks));
    }

    private function row(array $cells): string
    {
        return '| '.implode(' | ', array_map(fn ($cell): string => str_replace('|', '\\|', $this->display($cell)), $cells)).' |';
    }

    private function inline(array $values): string { return $values === [] ? '-' : implode(', ', array_map(fn ($v): string => '`'.$this->md((string) $v).'`', $values)); }
    private function scalar(mixed $value): string { return '`'.$this->md(is_array($value) ? implode(', ', $value) : (string) $value).'`'; }
    private function display(mixed $value): string { return is_array($value) ? implode(', ', array_map('strval', $value)) : (string) ($value ?? '-'); }
    private function md(string $value): string { return str_replace(["\r", "\n"], ' ', $value); }
}
