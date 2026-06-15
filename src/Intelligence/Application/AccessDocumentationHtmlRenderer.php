<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateAccessProbe;
use App\Intelligence\Domain\ProcessTemplateManualAccessTest;
use App\Intelligence\Domain\ProcessTemplateVisibilityProfile;
use App\Intelligence\Domain\ProcessTemplateVisibilityProfileResolver;

final class AccessDocumentationHtmlRenderer
{
    public function __construct(
        private readonly AccessCoverageReportBuilder $coverageReportBuilder = new AccessCoverageReportBuilder()
    ) {
    }

    public function render(ProcessTemplate $template): string
    {
        $report = $this->coverageReportBuilder->build($template);
        $title = sprintf('Access-/Visibility-Dokumentation: %s', $template->key);

        return implode("\n", [
            '<!doctype html>',
            '<html lang="de">',
            '<head>',
            '  <meta charset="utf-8">',
            '  <meta name="viewport" content="width=device-width, initial-scale=1">',
            '  <title>'.$this->e($title).'</title>',
            '  <style>',
            '    :root { color-scheme: light; --ink: #17202a; --muted: #5d6d7e; --line: #d7dbdd; --soft: #f4f6f7; --accent: #1f6f5b; }',
            '    body { font-family: Arial, Helvetica, sans-serif; margin: 2rem; color: var(--ink); line-height: 1.45; }',
            '    h1, h2, h3 { line-height: 1.2; }',
            '    h1 { margin-bottom: 0.4rem; }',
            '    h2 { margin-top: 2rem; border-bottom: 1px solid var(--line); padding-bottom: 0.35rem; }',
            '    code { background: var(--soft); padding: 0.1rem 0.3rem; border-radius: 0.25rem; }',
            '    table { border-collapse: collapse; width: 100%; margin: 0.75rem 0 1.25rem; }',
            '    th, td { border: 1px solid var(--line); padding: 0.5rem 0.6rem; text-align: left; vertical-align: top; }',
            '    th { background: var(--soft); }',
            '    .meta, .muted { color: var(--muted); }',
            '    .summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.75rem; margin: 1rem 0; }',
            '    .metric { border: 1px solid var(--line); border-radius: 0.4rem; padding: 0.7rem; background: #fff; }',
            '    .metric strong { display: block; color: var(--accent); font-size: 1.35rem; }',
            '    .manual-test { border: 1px solid var(--line); border-radius: 0.4rem; padding: 1rem; margin: 1rem 0; }',
            '  </style>',
            '</head>',
            '<body>',
            '  <h1>'.$this->e($title).'</h1>',
            '  <p class="meta">ProcessKey: <code>'.$this->e($template->key).'</code> · sourceSystem: <code>'.$this->e($template->sourceSystem).'</code></p>',
            $this->coverageSummary($report),
            $this->accessProbes($template),
            $this->visibilityProfiles($template),
            $this->visibilityResolvers($template),
            $this->stepChecks($report),
            $this->manualAccessTests($template),
            '</body>',
            '</html>',
            '',
        ]);
    }

    private function coverageSummary(AccessCoverageReport $report): string
    {
        return implode("\n", [
            '  <h2>Coverage Summary</h2>',
            '  <div class="summary">',
            $this->metric('automatic', (string) $report->summary['automatic']),
            $this->metric('unsupported', (string) $report->summary['unsupported']),
            $this->metric('incomplete/notCovered', (string) $report->summary['notCovered']),
            $this->metric('manualAccessTests', (string) $report->summary['manualAccessTests']),
            '  </div>',
        ]);
    }

    private function metric(string $label, string $value): string
    {
        return sprintf('    <div class="metric"><strong>%s</strong>%s</div>', $this->e($value), $this->e($label));
    }

    private function accessProbes(ProcessTemplate $template): string
    {
        $rows = [];
        foreach ($template->accessProbes as $probe) {
            $rows[] = $this->probeRow($probe);
        }

        return implode("\n", [
            '  <h2>Access-Probes</h2>',
            $this->table(['key', 'sourceSystem', 'type', 'description', 'maxDocuments'], $rows),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function probeRow(ProcessTemplateAccessProbe $probe): array
    {
        return [
            $probe->key,
            $probe->sourceSystem,
            $probe->type,
            $probe->description ?? '',
            $probe->maxDocuments === null ? '' : (string) $probe->maxDocuments,
        ];
    }

    private function visibilityProfiles(ProcessTemplate $template): string
    {
        $rows = [];
        foreach ($template->visibilityProfiles as $profile) {
            $rows[] = $this->profileRow($profile);
        }

        return implode("\n", [
            '  <h2>Visibility Check Profiles</h2>',
            $this->table(['key', 'expected visible in probes', 'expected not visible in probes'], $rows),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function profileRow(ProcessTemplateVisibilityProfile $profile): array
    {
        return [
            $profile->key,
            implode(', ', $profile->expectedVisibleInProbeKeys),
            implode(', ', $profile->expectedNotVisibleInProbeKeys),
        ];
    }

    private function visibilityResolvers(ProcessTemplate $template): string
    {
        $rows = [];
        foreach ($template->visibilityProfileResolvers as $resolver) {
            $rows[] = $this->resolverRow($resolver);
        }

        return implode("\n", [
            '  <h2>Visibility Profile Resolvers</h2>',
            $this->table(['key', 'field', 'map'], $rows),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function resolverRow(ProcessTemplateVisibilityProfileResolver $resolver): array
    {
        $map = [];
        foreach ($resolver->map as $value => $profileKey) {
            $map[] = sprintf('%s -> %s', $value, $profileKey);
        }

        return [$resolver->key, $resolver->field, implode(', ', $map)];
    }

    private function stepChecks(AccessCoverageReport $report): string
    {
        $rows = [];
        foreach ($report->checks as $check) {
            $rows[] = [
                (string) $check['stepKey'],
                (string) $check['phase'],
                (string) $check['checkKey'],
                (string) ($check['expectedProfile'] ?? ''),
                (string) ($check['expectedProfileResolver'] ?? ''),
                (string) ($check['retryPolicy'] ?? ''),
                (string) $check['coverage'],
                (string) ($check['reason'] ?? ''),
            ];
        }

        return implode("\n", [
            '  <h2>Step-nahe Visibility-Checks</h2>',
            $this->table(['stepKey', 'phase', 'checkKey', 'expectedProfile', 'expectedProfileResolver', 'retryPolicy', 'coverage', 'reason'], $rows),
        ]);
    }

    private function manualAccessTests(ProcessTemplate $template): string
    {
        $sections = ['  <h2>Manual Access Tests</h2>'];
        if ($template->manualAccessTests === []) {
            $sections[] = '  <p>Keine manuellen Access-Tests definiert.</p>';

            return implode("\n", $sections);
        }

        foreach ($template->manualAccessTests as $manualTest) {
            $sections[] = $this->manualAccessTest($manualTest);
        }

        return implode("\n", $sections);
    }

    private function manualAccessTest(ProcessTemplateManualAccessTest $manualTest): string
    {
        return implode("\n", [
            '  <section class="manual-test">',
            '    <h3>'.$this->e($manualTest->title ?? $manualTest->key).'</h3>',
            '    <p><strong>Key:</strong> <code>'.$this->e($manualTest->key).'</code></p>',
            '    <p><strong>Description:</strong> '.$this->e($manualTest->description ?? '-').'</p>',
            '    <p><strong>Frequency:</strong> '.$this->e($manualTest->frequency ?? '-').'</p>',
            '    <p><strong>Evidence required:</strong> '.$this->e($manualTest->evidenceRequired ?? '-').'</p>',
            '    <h4>Test Procedure</h4>',
            $this->orderedList($manualTest->testProcedure),
            '    <h4>Expected Result</h4>',
            $this->unorderedList($manualTest->expectedResult),
            '  </section>',
        ]);
    }

    /**
     * @param array<int, string> $headers
     * @param array<int, array<int, string>> $rows
     */
    private function table(array $headers, array $rows): string
    {
        if ($rows === []) {
            return '  <p>Keine Eintraege.</p>';
        }

        $lines = ['  <table>', '    <thead><tr>'];
        foreach ($headers as $header) {
            $lines[] = '      <th>'.$this->e($header).'</th>';
        }
        $lines[] = '    </tr></thead>';
        $lines[] = '    <tbody>';

        foreach ($rows as $row) {
            $lines[] = '      <tr>';
            foreach ($row as $cell) {
                $lines[] = '        <td>'.$this->e($cell).'</td>';
            }
            $lines[] = '      </tr>';
        }

        $lines[] = '    </tbody>';
        $lines[] = '  </table>';

        return implode("\n", $lines);
    }

    /**
     * @param array<int, string> $items
     */
    private function orderedList(array $items): string
    {
        if ($items === []) {
            return '    <p>-</p>';
        }

        $lines = ['    <ol>'];
        foreach ($items as $item) {
            $lines[] = '      <li>'.$this->e($item).'</li>';
        }
        $lines[] = '    </ol>';

        return implode("\n", $lines);
    }

    /**
     * @param array<int, string> $items
     */
    private function unorderedList(array $items): string
    {
        if ($items === []) {
            return '    <p>-</p>';
        }

        $lines = ['    <ul>'];
        foreach ($items as $item) {
            $lines[] = '      <li>'.$this->e($item).'</li>';
        }
        $lines[] = '    </ul>';

        return implode("\n", $lines);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
