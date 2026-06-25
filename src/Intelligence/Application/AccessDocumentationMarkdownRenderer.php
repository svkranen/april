<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateAccessProbe;
use App\Intelligence\Domain\ProcessTemplateManualAccessTest;
use App\Intelligence\Domain\ProcessTemplateStep;
use App\Intelligence\Domain\ProcessTemplateVisibilityCheck;
use App\Intelligence\Domain\ProcessTemplateVisibilityProfile;
use App\Intelligence\Domain\ProcessTemplateVisibilityProfileResolver;

final class AccessDocumentationMarkdownRenderer
{
    public function __construct(
        private readonly AccessCoverageReportBuilder $coverageReportBuilder = new AccessCoverageReportBuilder()
    ) {
    }

    public function render(ProcessTemplate $template): string
    {
        $report = $this->coverageReportBuilder->build($template);
        $lines = [
            sprintf('# Access-/Visibility-Dokumentation: %s', $template->key),
            '',
            sprintf('- ProcessKey: `%s`', $template->key),
            sprintf('- sourceSystem: `%s`', $template->sourceSystem),
            '',
            '## Coverage-Zusammenfassung',
            '',
            sprintf('- automatic: %d', $report->summary['automatic']),
            sprintf('- unsupported: %d', $report->summary['unsupported']),
            sprintf('- incomplete/notCovered: %d', $report->summary['notCovered']),
            sprintf('- manualAccessTests: %d', $report->summary['manualAccessTests']),
            '',
            '## Access-Probes',
            '',
        ];

        if ($template->accessProbes === []) {
            $lines[] = 'Keine Access-Probes definiert.';
        } else {
            foreach ($template->accessProbes as $probe) {
                $lines = array_merge($lines, $this->probeLines($probe));
            }
        }

        $lines = array_merge($lines, [
            '',
            '## Visibility-Check-Profile',
            '',
        ]);

        if ($template->visibilityProfiles === []) {
            $lines[] = 'Keine Visibility-Check-Profile definiert.';
        } else {
            foreach ($template->visibilityProfiles as $profile) {
                $lines = array_merge($lines, $this->profileLines($profile));
            }
        }

        $lines = array_merge($lines, [
            '',
            '## Visibility-Profile-Resolver',
            '',
        ]);

        if ($template->visibilityProfileResolvers === []) {
            $lines[] = 'Keine Visibility-Profile-Resolver definiert.';
        } else {
            foreach ($template->visibilityProfileResolvers as $resolver) {
                $lines = array_merge($lines, $this->resolverLines($resolver));
            }
        }

        $lines = array_merge($lines, [
            '',
            '## Step-nahe Visibility-Checks',
            '',
        ]);

        $checkLines = $this->stepCheckLines($template);
        $lines = array_merge($lines, $checkLines === [] ? ['Keine step-nahen Visibility-Checks definiert.'] : $checkLines);

        $lines = array_merge($lines, [
            '',
            '## Manual Access Tests',
            '',
        ]);

        if ($template->manualAccessTests === []) {
            $lines[] = 'Keine manuellen Access-Tests definiert.';
        } else {
            foreach ($template->manualAccessTests as $manualTest) {
                $lines = array_merge($lines, $this->manualTestLines($manualTest));
            }
        }

        return rtrim(implode("\n", $lines))."\n";
    }

    /**
     * @return array<int, string>
     */
    private function probeLines(ProcessTemplateAccessProbe $probe): array
    {
        return [
            sprintf('### `%s`', $probe->key),
            '',
            sprintf('- sourceSystem: `%s`', $probe->sourceSystem),
            sprintf('- type: `%s`', $probe->type),
            sprintf('- description: %s', $probe->description ?? '-'),
            sprintf('- maxDocuments: %s', $probe->maxDocuments === null ? '-' : (string) $probe->maxDocuments),
            '',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function profileLines(ProcessTemplateVisibilityProfile $profile): array
    {
        return [
            sprintf('### `%s`', $profile->key),
            '',
            sprintf('- expectedVisibleInProbes: %s', $this->inlineList($profile->expectedVisibleInProbeKeys)),
            sprintf('- expectedNotVisibleInProbes: %s', $this->inlineList($profile->expectedNotVisibleInProbeKeys)),
            '',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function resolverLines(ProcessTemplateVisibilityProfileResolver $resolver): array
    {
        $lines = [
            sprintf('### `%s`', $resolver->key),
            '',
            sprintf('- field: `%s`', $resolver->field),
            '- map:',
        ];

        if ($resolver->map === []) {
            $lines[] = '  - -';
        } else {
            foreach ($resolver->map as $value => $profileKey) {
                $lines[] = sprintf('  - `%s` -> `%s`', $value, $profileKey);
            }
        }

        $lines[] = '';

        return $lines;
    }

    /**
     * @return array<int, string>
     */
    private function stepCheckLines(ProcessTemplate $template): array
    {
        $lines = [];
        foreach ($template->steps as $step) {
            $lines = array_merge($lines, $this->phaseCheckLines($step, $step->beforeVisibilityChecks));
            $lines = array_merge($lines, $this->phaseCheckLines($step, $step->afterVisibilityChecks));
        }

        return $lines;
    }

    /**
     * @param array<int, ProcessTemplateVisibilityCheck> $checks
     * @return array<int, string>
     */
    private function phaseCheckLines(ProcessTemplateStep $step, array $checks): array
    {
        $lines = [];
        foreach ($checks as $check) {
            $lines[] = sprintf('### `%s` / `%s` / `%s`', $step->key, $check->phase, $check->key);
            $lines[] = '';
            $lines[] = sprintf('- stepKey: `%s`', $step->key);
            $lines[] = sprintf('- phase: `%s`', $check->phase);
            $lines[] = sprintf('- checkKey: `%s`', $check->key);
            $lines[] = sprintf('- expectedProfile: %s', $check->expectedProfileKey === null ? '-' : '`'.$check->expectedProfileKey.'`');
            $lines[] = sprintf('- expectedProfileResolver: %s', $check->expectedProfileResolverKey === null ? '-' : '`'.$check->expectedProfileResolverKey.'`');
            $lines[] = sprintf('- retryPolicy: %s', $check->retryPolicyKey === null ? '-' : '`'.$check->retryPolicyKey.'`');
            $lines[] = '';
        }

        return $lines;
    }

    /**
     * @return array<int, string>
     */
    private function manualTestLines(ProcessTemplateManualAccessTest $manualTest): array
    {
        $lines = [
            sprintf('### `%s`', $manualTest->key),
            '',
            sprintf('- title: %s', $manualTest->title ?? '-'),
            sprintf('- description: %s', $manualTest->description ?? '-'),
            sprintf('- frequency: %s', $manualTest->frequency ?? '-'),
            sprintf('- evidenceRequired: %s', $manualTest->evidenceRequired ?? '-'),
            '',
            'Test Procedure:',
            '',
        ];

        if ($manualTest->testProcedure === []) {
            $lines[] = '1. -';
        } else {
            foreach ($manualTest->testProcedure as $index => $step) {
                $lines[] = sprintf('%d. %s', $index + 1, $step);
            }
        }

        $lines[] = '';
        $lines[] = 'Expected Result:';
        $lines[] = '';

        if ($manualTest->expectedResult === []) {
            $lines[] = '- -';
        } else {
            foreach ($manualTest->expectedResult as $expected) {
                $lines[] = sprintf('- %s', $expected);
            }
        }

        $lines[] = '';

        return $lines;
    }

    /**
     * @param array<int, string> $values
     */
    private function inlineList(array $values): string
    {
        if ($values === []) {
            return '-';
        }

        return implode(', ', array_map(static fn (string $value): string => '`'.$value.'`', $values));
    }
}
