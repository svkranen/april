<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateAccessProbe;
use App\Intelligence\Domain\ProcessTemplateStep;
use App\Intelligence\Domain\ProcessTemplateVisibilityCheck;

final class AccessCoverageReportBuilder
{
    /**
     * @var array<string, array<int, string>>
     */
    private const SUPPORTED_PROBE_TYPES = [
        'amagno' => ['amagno_magnet_documents'],
    ];

    public function build(ProcessTemplate $template): AccessCoverageReport
    {
        $checks = [];
        foreach ($template->steps as $step) {
            foreach ($step->beforeVisibilityChecks as $check) {
                $checks[] = $this->checkRow($template, $step, $check);
            }
            foreach ($step->afterVisibilityChecks as $check) {
                $checks[] = $this->checkRow($template, $step, $check);
            }
        }

        $manualTests = array_map(
            static fn ($test): array => [
                'key' => $test->key,
                'title' => $test->title,
                'frequency' => $test->frequency,
                'description' => $test->description,
                'testProcedure' => $test->testProcedure,
                'expectedResult' => $test->expectedResult,
                'evidenceRequired' => $test->evidenceRequired,
            ],
            $template->manualAccessTests
        );

        return new AccessCoverageReport(
            $template->key,
            $template->sourceSystem,
            $checks,
            $manualTests,
            $this->summary($template, $checks)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function checkRow(ProcessTemplate $template, ProcessTemplateStep $step, ProcessTemplateVisibilityCheck $check): array
    {
        $profileKeys = $this->profileKeys($template, $check);
        $probeKeys = $this->probeKeys($template, $profileKeys);
        $probeRows = [];
        $missingProbeKeys = [];
        $unsupportedProbeKeys = [];

        foreach ($probeKeys as $probeKey) {
            $probe = $template->accessProbes[$probeKey] ?? null;
            if (!$probe instanceof ProcessTemplateAccessProbe) {
                $missingProbeKeys[] = $probeKey;
                continue;
            }

            $sourceSystem = $check->sourceSystemOverride ?? $probe->sourceSystem;
            $supported = $this->isSupported($sourceSystem, $probe->type);
            if (!$supported) {
                $unsupportedProbeKeys[] = $probeKey;
            }

            $probeRows[] = [
                'key' => $probe->key,
                'sourceSystem' => $sourceSystem,
                'type' => $probe->type,
                'supported' => $supported,
            ];
        }

        $status = 'automatic';
        $reason = null;
        if ($profileKeys === []) {
            $status = 'not_covered';
            $reason = 'missing_profile_or_resolver';
        } elseif ($probeKeys === []) {
            $status = 'not_covered';
            $reason = 'profile_without_probes';
        } elseif ($missingProbeKeys !== []) {
            $status = 'not_covered';
            $reason = 'missing_probe';
        } elseif ($unsupportedProbeKeys !== []) {
            $status = 'unsupported';
            $reason = 'unsupported_probe_type';
        }

        return [
            'stepKey' => $step->key,
            'phase' => $check->phase,
            'checkKey' => $check->key,
            'expectedProfile' => $check->expectedProfileKey,
            'expectedProfileResolver' => $check->expectedProfileResolverKey,
            'retryPolicy' => $check->retryPolicyKey,
            'sourceSystemOverride' => $check->sourceSystemOverride,
            'profileKeys' => $profileKeys,
            'probeKeys' => $probeKeys,
            'probes' => $probeRows,
            'coverage' => $status,
            'reason' => $reason,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function profileKeys(ProcessTemplate $template, ProcessTemplateVisibilityCheck $check): array
    {
        if ($check->expectedProfileKey !== null) {
            return isset($template->visibilityProfiles[$check->expectedProfileKey])
                ? [$check->expectedProfileKey]
                : [];
        }

        if ($check->expectedProfileResolverKey === null) {
            return [];
        }

        $resolver = $template->visibilityProfileResolvers[$check->expectedProfileResolverKey] ?? null;
        if ($resolver === null) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_values($resolver->map),
            static fn (string $profileKey): bool => isset($template->visibilityProfiles[$profileKey])
        )));
    }

    /**
     * @param array<int, string> $profileKeys
     * @return array<int, string>
     */
    private function probeKeys(ProcessTemplate $template, array $profileKeys): array
    {
        $probeKeys = [];
        foreach ($profileKeys as $profileKey) {
            $profile = $template->visibilityProfiles[$profileKey] ?? null;
            if ($profile === null) {
                continue;
            }

            foreach (array_merge($profile->expectedVisibleInProbeKeys, $profile->expectedNotVisibleInProbeKeys) as $probeKey) {
                $probeKeys[] = $probeKey;
            }
        }

        return array_values(array_unique($probeKeys));
    }

    private function isSupported(string $sourceSystem, string $type): bool
    {
        return in_array($type, self::SUPPORTED_PROBE_TYPES[$sourceSystem] ?? [], true);
    }

    /**
     * @param array<int, array<string, mixed>> $checks
     * @return array<string, int>
     */
    private function summary(ProcessTemplate $template, array $checks): array
    {
        $summary = [
            'accessProbes' => count($template->accessProbes),
            'visibilityChecks' => count($checks),
            'automatic' => 0,
            'unsupported' => 0,
            'notCovered' => 0,
            'manualAccessTests' => count($template->manualAccessTests),
        ];

        foreach ($checks as $check) {
            match ($check['coverage']) {
                'automatic' => ++$summary['automatic'],
                'unsupported' => ++$summary['unsupported'],
                default => ++$summary['notCovered'],
            };
        }

        return $summary;
    }
}
