<?php

namespace App\Intelligence\Application;

final class HtmlProcessTemplateDocumentationRenderer implements ProcessTemplateDocumentationRendererInterface
{
    public function render(ProcessTemplateDocumentation $documentation): string
    {
        $title = $documentation->title ?? $documentation->processKey;
        $body = [
            '<h1>'.$this->e($title).'</h1>',
            '<h2>Metadaten</h2>',
            $this->table(['Feld', 'Wert'], [
                ['processKey', $documentation->processKey],
                ['version', $documentation->version],
                ['sourceSystem', $documentation->sourceSystem],
                ['Template-Dateipfad', $documentation->templatePath],
                ['Generiert am', $documentation->generatedAt],
            ]),
            '<h2>Management Summary</h2>',
            $this->keyValueTable($documentation->summary),
            '<aside>Das YAML-Template ist die Source of Truth.</aside>',
            '<h2>Prozessschritte</h2>',
            '<aside>before/after sind Kontrollphasen am gleichen stepKey und keine eigenen Prozessschritte.</aside>',
            $this->steps($documentation->steps),
            '<h2>Soll-Prozess / Übergänge</h2>',
            $this->transitions($documentation),
            '<h2>Decision Points</h2>',
            $this->decisionPoints($documentation->decisionPoints),
            '<h2>Context / Field Mapping</h2>',
            '<p><strong>context_profile.required:</strong> '.$this->list($documentation->requiredContextFields).'</p>',
            $this->table(['Feld', 'Quelle', 'tag_id', 'tag_name', 'value_type', 'stability'], array_map('array_values', $documentation->fieldMappings)),
            '<h3>Context Policy</h3>',
            $documentation->contextPolicy === [] ? '<p>Keine Context Policy definiert.</p>' : $this->keyValueTable($documentation->contextPolicy),
            '<h2>SignChecks</h2>',
            $this->table(['Key', 'Label', 'Soll-Feld', 'Ist-Feld', 'Operator'], array_map('array_values', $documentation->signChecks)),
            $this->access($documentation->access),
            '<h2>Warnungen</h2>',
            $this->unorderedList($documentation->warnings ?: ['Keine strukturellen Mapping-Warnungen.']),
            '<h2>Grenzen / Annahmen</h2>',
            $this->unorderedList($documentation->assumptions),
        ];

        return "<!doctype html>\n<html lang=\"de\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n<title>".$this->e($title)." – APRIL Prozessdokumentation</title>\n<style>body{font:16px/1.5 system-ui,sans-serif;max-width:1100px;margin:2rem auto;padding:0 1.5rem;color:#17202a}h1,h2,h3,h4{line-height:1.2;overflow-wrap:anywhere}h2{margin-top:2.5rem;border-bottom:1px solid #d7dbdd;padding-bottom:.35rem}.table-scroll{max-width:100%;overflow-x:auto;margin:1rem 0}table{border-collapse:collapse;width:100%;min-width:36rem}th,td{border:1px solid #d7dbdd;padding:.5rem;text-align:left;vertical-align:top;overflow-wrap:anywhere}th{background:#f4f6f7}code{background:#f4f6f7;padding:.1rem .3rem;border-radius:.2rem;overflow-wrap:anywhere}aside{border-left:4px solid #1f6f5b;background:#f4f6f7;padding:.75rem 1rem}@media(max-width:640px){body{margin:1rem auto;padding:0 .75rem}table{font-size:.9rem}}@media print{body{max-width:none;margin:0;color:#000}.table-scroll{overflow:visible}table{min-width:0;break-inside:avoid}aside{border-color:#000;background:#fff}}</style>\n</head>\n<body>\n".implode("\n", $body)."\n</body>\n</html>\n";
    }

    private function steps(array $steps): string
    {
        if ($steps === []) {
            return '<p>Keine Prozessschritte definiert.</p>';
        }
        $html = [];
        foreach ($steps as $step) {
            $html[] = '<section><h3><code>'.$this->e($step['key']).'</code> — '.$this->e($step['label']).'</h3>';
            $html[] = '<p>Typ: <code>'.$this->e($step['type']).'</code> · Erforderlich: '.($step['required'] ? 'ja' : 'nein').'</p>';
            $html[] = '<p>before-Kontrollphasen: '.$this->checks($step['beforeChecks']).'</p>';
            $html[] = '<p>after-Kontrollphasen: '.$this->checks($step['afterChecks']).'</p></section>';
        }
        return implode("\n", $html);
    }

    private function transitions(ProcessTemplateDocumentation $doc): string
    {
        $rows = array_map(static fn ($row): array => [$row['from'], $row['toParallelGroup'] === null ? 'Step' : 'Parallelgruppe', $row['to'] ?? $row['toParallelGroup']], $doc->transitions);
        $groups = array_map(fn ($group): string => sprintf('%s: nach %s; Pflichtschritte %s; Reihenfolge %s; weiter %s', $group['key'], $group['after'] ?? '-', implode(', ', $group['requiredSteps']), $group['order'], $group['next'] ?? '-'), $doc->parallelGroups);
        return $this->table(['Von', 'Zieltyp', 'Ziel'], $rows).'<h3>Parallelgruppen</h3>'.$this->unorderedList($groups ?: ['Keine Parallelgruppen definiert.']);
    }

    private function decisionPoints(array $points): string
    {
        if ($points === []) {
            return '<p>Keine Decision Points definiert.</p>';
        }
        $html = [];
        foreach ($points as $point) {
            $rules = [];
            foreach ($point['rules'] as $rule) {
                $condition = $rule['else'] ? 'Sonst' : sprintf('Wenn %s %s %s', $rule['field'], $rule['operator'], $this->display($rule['value']));
                $rules[] = sprintf('%s, dann %s %s.', $condition, $rule['targetType'], $rule['target'] ?? '-');
            }
            $html[] = '<section><h3><code>'.$this->e($point['key']).'</code></h3><p>Nach Step: <code>'.$this->e($point['after'] ?? '-').'</code></p><p>Benötigte Felder: '.$this->list($point['requiredFields']).'</p>'.$this->unorderedList($rules).'</section>';
        }
        return implode("\n", $html);
    }

    private function access(array $access): string
    {
        $sections = ['<h2>Access-/Visibility Summary</h2>', '<aside>APRIL dokumentiert definierte Probes und Kontrollen, ist aber keine vollständige ACL-Engine.</aside>', '<h3>Coverage Summary</h3>', $this->keyValueTable($access['coverage'])];
        $definitions = [
            ['Access-Probes', 'probes', ['key', 'sourceSystem', 'type', 'description', 'maxDocuments']],
            ['Visibility Profiles', 'profiles', ['key', 'visible', 'notVisible']],
            ['Visibility Profile Resolver', 'resolvers', ['key', 'field', 'map']],
            ['Retry Policies', 'retryPolicies', ['key', 'attemptsAfterSeconds', 'forbiddenFound', 'expectedMissing', 'probeTooLarge']],
            ['Step-nahe Visibility Checks', 'checks', ['stepKey', 'phase', 'checkKey', 'expectedProfile', 'expectedProfileResolver', 'retryPolicy', 'coverage']],
        ];
        foreach ($definitions as [$title, $key, $columns]) {
            $sections[] = '<h3>'.$this->e($title).'</h3>';
            $sections[] = $this->table($columns, array_map(static fn ($row): array => array_map(static fn ($column) => $row[$column] ?? null, $columns), $access[$key]));
        }
        $sections[] = '<h3>Manual Access Tests</h3>';
        foreach ($access['manualTests'] as $test) {
            $sections[] = '<section><h4><code>'.$this->e($test['key']).'</code> — '.$this->e($test['title'] ?? $test['key']).'</h4><p>'.$this->e($test['description'] ?? '-').'</p><p>Frequenz: '.$this->e($test['frequency'] ?? '-').' · Evidenz: '.$this->e($test['evidenceRequired'] ?? '-').'</p><h5>Test Procedure</h5>'.$this->unorderedList($test['testProcedure']).'<h5>Expected Result</h5>'.$this->unorderedList($test['expectedResult']).'</section>';
        }
        if ($access['manualTests'] === []) {
            $sections[] = '<p>Keine manuellen Access Tests definiert.</p>';
        }
        return implode("\n", $sections);
    }

    private function checks(array $checks): string
    {
        if ($checks === []) {
            return '-';
        }
        return implode('; ', array_map(fn ($check): string => '<code>'.$this->e($check['key']).'</code> (Profil: <code>'.$this->e($check['expectedProfile'] ?? '-').'</code>, Resolver: <code>'.$this->e($check['expectedProfileResolver'] ?? '-').'</code>, Retry: <code>'.$this->e($check['retryPolicy'] ?? '-').'</code>)', $checks));
    }

    private function keyValueTable(array $values): string
    {
        return $this->table(
            ['Kennzahl', 'Wert'],
            array_map(static fn ($key, $value): array => [$key, $value], array_keys($values), array_values($values))
        );
    }

    private function list(array $values): string
    {
        return $values === []
            ? '-'
            : implode(', ', array_map(fn ($value): string => '<code>'.$this->e((string) $value).'</code>', $values));
    }

    private function unorderedList(array $values): string
    {
        return '<ul>'.implode('', array_map(fn ($value): string => '<li>'.$this->e((string) $value).'</li>', $values)).'</ul>';
    }

    private function table(array $headers, array $rows): string
    {
        if ($rows === []) {
            return '<p>Keine Einträge.</p>';
        }
        $head = implode('', array_map(fn ($header): string => '<th scope="col">'.$this->e((string) $header).'</th>', $headers));
        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr>'.implode('', array_map(fn ($value): string => '<td>'.$this->e($this->display($value)).'</td>', $row)).'</tr>';
        }
        return '<div class="table-scroll"><table><thead><tr>'.$head.'</tr></thead><tbody>'.$body.'</tbody></table></div>';
    }

    private function display(mixed $value): string
    {
        if (!is_array($value)) {
            return (string) ($value ?? '-');
        }
        $parts = [];
        foreach ($value as $key => $item) {
            $parts[] = is_string($key) ? $key.' -> '.$item : (string) $item;
        }
        return implode(', ', $parts);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
