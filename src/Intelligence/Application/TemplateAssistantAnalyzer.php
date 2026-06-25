<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateStep;
use App\Intelligence\Domain\ProcessTemplateTransition;

/**
 * Derives the read-only template assistant view: a readable projection of a
 * process template plus consistency checks that help maintain the Soll process.
 *
 * Pure / side-effect free. It only reads the already-parsed domain template -
 * no file access, no writing, no suggestions are applied. All checks are derived
 * from the current structure; anything that cannot be decided safely is left out
 * rather than guessed.
 */
final class TemplateAssistantAnalyzer
{
    public function analyze(ProcessTemplate $template, ?string $filePath = null): TemplateAssistantView
    {
        $stepKeyList = array_map(static fn (ProcessTemplateStep $step): string => $step->key, $template->steps);
        $knownSteps = array_fill_keys($stepKeyList, true);
        $knownGroups = [];
        foreach ($template->parallelGroups as $group) {
            $knownGroups[$group->key] = true;
        }

        $steps = [];
        $position = 0;
        foreach ($template->steps as $step) {
            $steps[] = [
                'position' => ++$position,
                'key' => $step->key,
                'name' => $step->name,
                'type' => $step->type,
            ];
        }

        [$transitions, $unknownRefs, $duplicateTransitions] = $this->analyzeTransitions($template->transitions, $knownSteps, $knownGroups);

        $distinctStepKeys = array_values(array_unique($stepKeyList));
        $duplicateStepKeys = array_keys(array_filter(array_count_values($stepKeyList), static fn (int $count): bool => $count > 1));
        $requiredStepKeys = array_values($template->requiredStepKeys);

        $requiredNotInSteps = array_values(array_diff($requiredStepKeys, $distinctStepKeys));
        // Only meaningful when required_steps is declared; an empty list means
        // "all steps are required" (mirrors the check service), so flagging every
        // step would be misleading.
        $stepsNotInRequired = $requiredStepKeys === []
            ? []
            : array_values(array_diff($distinctStepKeys, $requiredStepKeys));

        $checks = [
            new TemplateAssistantCheck(
                'duplicate_steps',
                'Doppelte Schritt-Keys',
                TemplateAssistantCheck::STATUS_ERROR,
                $duplicateStepKeys,
                'Alle Schritt-Keys sind eindeutig.'
            ),
            new TemplateAssistantCheck(
                'unknown_transition_refs',
                'Übergänge mit unbekanntem from/to',
                TemplateAssistantCheck::STATUS_ERROR,
                $unknownRefs,
                'Alle Übergänge referenzieren bekannte Schritte/Parallelgruppen.'
            ),
            new TemplateAssistantCheck(
                'duplicate_transitions',
                'Doppelte Übergänge',
                TemplateAssistantCheck::STATUS_WARNING,
                $duplicateTransitions,
                'Keine doppelten Übergänge.'
            ),
            new TemplateAssistantCheck(
                'required_not_in_steps',
                'required_steps ohne passenden Schritt',
                TemplateAssistantCheck::STATUS_ERROR,
                $requiredNotInSteps,
                'Alle required_steps existieren als Schritt.'
            ),
            new TemplateAssistantCheck(
                'steps_not_in_required',
                'Schritte, die nicht in required_steps stehen',
                TemplateAssistantCheck::STATUS_WARNING,
                $stepsNotInRequired,
                $requiredStepKeys === []
                    ? 'Keine required_steps definiert – alle Schritte gelten als erforderlich.'
                    : 'Alle Schritte sind in required_steps enthalten.'
            ),
        ];

        // Incoming/outgoing checks only make sense for flat, purely transition-based
        // templates. With decision points or parallel groups connectivity is also
        // expressed elsewhere, so a transition-only check would raise false alarms.
        $structuralApplicable = $template->transitions !== []
            && $template->decisionPoints === []
            && $template->parallelGroups === [];

        if ($structuralApplicable) {
            [$noIncoming, $noOutgoing] = $this->analyzeConnectivity($template, $distinctStepKeys);
            $checks[] = new TemplateAssistantCheck(
                'steps_without_incoming',
                'Schritte ohne eingehende Transition',
                TemplateAssistantCheck::STATUS_WARNING,
                $noIncoming,
                'Jeder Schritt (außer dem Startschritt) hat eine eingehende Transition.'
            );
            $checks[] = new TemplateAssistantCheck(
                'steps_without_outgoing',
                'Schritte ohne ausgehende Transition',
                TemplateAssistantCheck::STATUS_WARNING,
                $noOutgoing,
                'Jeder Schritt hat eine ausgehende Transition.'
            );
        }

        $structuralNote = $structuralApplicable
            ? null
            : 'Strukturprüfung (Schritte ohne ein-/ausgehende Transition) ist im MVP nur für rein transitionsbasierte Templates ohne Decision Points und Parallelgruppen aktiv und wurde hier übersprungen.';

        return new TemplateAssistantView(
            $template->key,
            $template->version,
            $template->name,
            $template->sourceSystem,
            $filePath,
            $template->initialStepKey,
            $steps,
            $transitions,
            $requiredStepKeys,
            $checks,
            $this->overallStatus($checks),
            $structuralApplicable,
            $structuralNote
        );
    }

    /**
     * @param array<int, ProcessTemplateTransition> $transitions
     * @param array<string, bool> $knownSteps
     * @param array<string, bool> $knownGroups
     * @return array{0: array<int, array{from: string, toDisplay: string, fromKnown: bool, toKnown: bool, targetKind: string}>, 1: array<int, string>, 2: array<int, string>}
     */
    private function analyzeTransitions(array $transitions, array $knownSteps, array $knownGroups): array
    {
        $rows = [];
        $unknownRefs = [];
        $seen = [];
        $duplicates = [];

        foreach ($transitions as $transition) {
            $fromKnown = isset($knownSteps[$transition->from]);

            if ($transition->to !== null) {
                $targetKind = 'step';
                $toDisplay = $transition->to;
                $toKnown = isset($knownSteps[$transition->to]);
            } elseif ($transition->toParallelGroup !== null) {
                $targetKind = 'parallel';
                $toDisplay = 'Parallelgruppe: '.$transition->toParallelGroup;
                $toKnown = isset($knownGroups[$transition->toParallelGroup]);
            } else {
                $targetKind = 'none';
                $toDisplay = '—';
                $toKnown = false;
            }

            $rows[] = [
                'from' => $transition->from,
                'toDisplay' => $toDisplay,
                'fromKnown' => $fromKnown,
                'toKnown' => $toKnown,
                'targetKind' => $targetKind,
            ];

            if (!$fromKnown) {
                $unknownRefs[] = sprintf('from "%s" ist kein bekannter Schritt', $transition->from);
            }
            if (!$toKnown) {
                $unknownRefs[] = match ($targetKind) {
                    'parallel' => sprintf('Parallelgruppe "%s" ist unbekannt (from "%s")', $transition->toParallelGroup, $transition->from),
                    'none' => sprintf('Übergang von "%s" hat kein Ziel', $transition->from),
                    default => sprintf('to "%s" ist kein bekannter Schritt (from "%s")', $transition->to, $transition->from),
                };
            }

            $signature = $transition->from."\0".($transition->to ?? '')."\0".($transition->toParallelGroup ?? '');
            if (isset($seen[$signature])) {
                $duplicates[$signature] = sprintf('%s → %s', $transition->from, $toDisplay);
            } else {
                $seen[$signature] = true;
            }
        }

        return [$rows, $unknownRefs, array_values($duplicates)];
    }

    /**
     * Incoming/outgoing presence derived from the explicit transitions only.
     * The initial step is exempt from the incoming check.
     *
     * @param array<int, string> $distinctStepKeys
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private function analyzeConnectivity(ProcessTemplate $template, array $distinctStepKeys): array
    {
        $hasOutgoing = [];
        $hasIncoming = [];
        foreach ($template->transitions as $transition) {
            $hasOutgoing[$transition->from] = true;
            if ($transition->to !== null) {
                $hasIncoming[$transition->to] = true;
            }
        }

        $noIncoming = [];
        $noOutgoing = [];
        foreach ($distinctStepKeys as $stepKey) {
            if ($stepKey !== $template->initialStepKey && !isset($hasIncoming[$stepKey])) {
                $noIncoming[] = $stepKey;
            }
            if (!isset($hasOutgoing[$stepKey])) {
                $noOutgoing[] = $stepKey;
            }
        }

        return [$noIncoming, $noOutgoing];
    }

    /**
     * @param array<int, TemplateAssistantCheck> $checks
     */
    private function overallStatus(array $checks): string
    {
        $status = TemplateAssistantCheck::STATUS_OK;
        foreach ($checks as $check) {
            if ($check->status() === TemplateAssistantCheck::STATUS_ERROR) {
                return TemplateAssistantCheck::STATUS_ERROR;
            }
            if ($check->status() === TemplateAssistantCheck::STATUS_WARNING) {
                $status = TemplateAssistantCheck::STATUS_WARNING;
            }
        }

        return $status;
    }
}
