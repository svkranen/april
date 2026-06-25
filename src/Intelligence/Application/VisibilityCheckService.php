<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateAccessProbe;
use App\Intelligence\Domain\ProcessTemplateVisibilityCheck;

final readonly class VisibilityCheckService
{
    public function __construct(
        private AccessProbeProviderRegistry $providerRegistry,
        private VisibilityProfileResolver $profileResolver = new VisibilityProfileResolver()
    ) {
    }

    /**
     * @param array<string, mixed> $context
     * @return array<int, VisibilityCheckEvaluationResult>
     */
    public function evaluate(
        ProcessTemplate $template,
        string $documentUuid,
        string $stepKey,
        string $eventPhase,
        string $checkKey,
        array $context = []
    ): array {
        $check = $this->findCheck($template, $stepKey, $eventPhase, $checkKey);
        if ($check === null) {
            return [
                $this->result($documentUuid, $template->key, $stepKey, $eventPhase, $checkKey, '', '', '', AccessProbeResult::ACTUAL_SKIPPED, 'skipped', 'check_not_found'),
            ];
        }

        $resolution = $this->profileResolver->resolve($template, $check, $context);
        if (!$resolution->isResolved()) {
            return [
                $this->result($documentUuid, $template->key, $stepKey, $eventPhase, $checkKey, '', '', '', AccessProbeResult::ACTUAL_UNKNOWN, 'warning', $resolution->reason),
            ];
        }

        $profile = $template->visibilityProfiles[$resolution->profileKey] ?? null;
        if ($profile === null) {
            return [
                $this->result($documentUuid, $template->key, $stepKey, $eventPhase, $checkKey, (string) $resolution->profileKey, '', '', AccessProbeResult::ACTUAL_UNKNOWN, 'warning', 'unknown_profile'),
            ];
        }

        $results = [];
        foreach ($profile->expectedVisibleInProbeKeys as $probeKey) {
            $results[] = $this->evaluateProbe($template, $documentUuid, $stepKey, $eventPhase, $check, $resolution->profileKey, $probeKey, 'visible');
        }

        foreach ($profile->expectedNotVisibleInProbeKeys as $probeKey) {
            $results[] = $this->evaluateProbe($template, $documentUuid, $stepKey, $eventPhase, $check, $resolution->profileKey, $probeKey, 'hidden');
        }

        return $results;
    }

    private function findCheck(ProcessTemplate $template, string $stepKey, string $eventPhase, string $checkKey): ?ProcessTemplateVisibilityCheck
    {
        foreach ($template->steps as $step) {
            if ($step->key !== $stepKey) {
                continue;
            }

            $checks = $eventPhase === 'before' ? $step->beforeVisibilityChecks : $step->afterVisibilityChecks;
            foreach ($checks as $check) {
                if ($check->key === $checkKey) {
                    return $check;
                }
            }
        }

        return null;
    }

    private function evaluateProbe(
        ProcessTemplate $template,
        string $documentUuid,
        string $stepKey,
        string $eventPhase,
        ProcessTemplateVisibilityCheck $check,
        string $profileKey,
        string $probeKey,
        string $expected
    ): VisibilityCheckEvaluationResult {
        $probe = $template->accessProbes[$probeKey] ?? null;
        if (!$probe instanceof ProcessTemplateAccessProbe) {
            return $this->result($documentUuid, $template->key, $stepKey, $eventPhase, $check->key, $profileKey, $probeKey, $expected, AccessProbeResult::ACTUAL_SKIPPED, 'skipped', 'missing_probe');
        }

        $probe = $check->sourceSystemOverride === null
            ? $probe
            : new ProcessTemplateAccessProbe($probe->key, $check->sourceSystemOverride, $probe->type, $probe->options, $probe->maxDocuments, $probe->description);

        $probeResult = $this->providerRegistry->evaluate($probe, $documentUuid);
        [$status, $reason] = $this->status($expected, $probeResult, $probe);

        return $this->result(
            $documentUuid,
            $template->key,
            $stepKey,
            $eventPhase,
            $check->key,
            $profileKey,
            $probeKey,
            $expected,
            $probeResult->actual,
            $status,
            $reason,
            [
                'documentCount' => $probeResult->documentCount,
                'probeReason' => $probeResult->reason,
                'probeDetails' => $probeResult->details,
                'retryPolicy' => $check->retryPolicyKey,
            ]
        );
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function status(string $expected, AccessProbeResult $actual, ProcessTemplateAccessProbe $probe): array
    {
        if ($actual->documentCount !== null && $probe->maxDocuments !== null && $actual->documentCount > $probe->maxDocuments) {
            return ['technical_warning', 'probe_too_large'];
        }

        return match ($actual->actual) {
            AccessProbeResult::ACTUAL_UNKNOWN => ['unknown', $actual->reason],
            AccessProbeResult::ACTUAL_SKIPPED => [
                $actual->reason === 'unsupported_probe_type' ? 'skipped' : 'technical_warning',
                $actual->reason,
            ],
            AccessProbeResult::ACTUAL_VISIBLE => $expected === 'visible'
                ? ['ok', null]
                : ['violation', 'forbidden_visibility'],
            AccessProbeResult::ACTUAL_HIDDEN => $expected === 'hidden'
                ? ['ok', null]
                : ['warning', 'missing_expected_visibility'],
            default => ['unknown', 'unknown_actual'],
        };
    }

    /**
     * @param array<string, mixed> $details
     */
    private function result(
        string $documentUuid,
        string $processKey,
        string $stepKey,
        string $eventPhase,
        string $checkKey,
        string $profileKey,
        string $probeKey,
        string $expected,
        string $actual,
        string $status,
        ?string $reason = null,
        array $details = []
    ): VisibilityCheckEvaluationResult {
        return new VisibilityCheckEvaluationResult($documentUuid, $processKey, $stepKey, $eventPhase, $checkKey, $profileKey, $probeKey, $expected, $actual, $status, $reason, $details);
    }
}
