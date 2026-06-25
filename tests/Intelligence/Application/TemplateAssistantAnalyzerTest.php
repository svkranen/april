<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\TemplateAssistantAnalyzer;
use App\Intelligence\Application\TemplateAssistantCheck;
use App\Intelligence\Application\TemplateAssistantView;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateDecisionPoint;
use App\Intelligence\Domain\ProcessTemplateStep;
use App\Intelligence\Domain\ProcessTemplateTransition;
use PHPUnit\Framework\TestCase;

class TemplateAssistantAnalyzerTest extends TestCase
{
    private function analyzer(): TemplateAssistantAnalyzer
    {
        return new TemplateAssistantAnalyzer();
    }

    public function testDetectsUnknownTransitionTarget(): void
    {
        $template = new ProcessTemplate(
            key: 'demo',
            steps: [new ProcessTemplateStep('a'), new ProcessTemplateStep('b')],
            transitions: [new ProcessTemplateTransition('a', 'ghost')],
        );

        $view = $this->analyzer()->analyze($template);

        $check = $this->check($view, 'unknown_transition_refs');
        self::assertTrue($check->hasItems());
        self::assertSame(TemplateAssistantCheck::STATUS_ERROR, $check->status());
        self::assertSame(TemplateAssistantCheck::STATUS_ERROR, $view->overallStatus);
        // The rendered transition row marks the target as unknown.
        self::assertFalse($view->transitions[0]['toKnown']);
        self::assertTrue($view->transitions[0]['fromKnown']);
    }

    public function testTransitionToParallelGroupIsKnownWhenDeclared(): void
    {
        $template = new ProcessTemplate(
            key: 'demo',
            steps: [new ProcessTemplateStep('a')],
            transitions: [new ProcessTemplateTransition('a', null, 'grp')],
            parallelGroups: [new \App\Intelligence\Domain\ProcessTemplateParallelGroup('grp', null, ['a'])],
        );

        $view = $this->analyzer()->analyze($template);

        self::assertTrue($view->transitions[0]['toKnown']);
        self::assertSame('parallel', $view->transitions[0]['targetKind']);
        self::assertFalse($this->check($view, 'unknown_transition_refs')->hasItems());
    }

    public function testDetectsRequiredStepWithoutStep(): void
    {
        $template = new ProcessTemplate(
            key: 'demo',
            steps: [new ProcessTemplateStep('a')],
            requiredStepKeys: ['a', 'missing'],
        );

        $check = $this->check($this->analyzer()->analyze($template), 'required_not_in_steps');

        self::assertSame(['missing'], $check->items);
        self::assertSame(TemplateAssistantCheck::STATUS_ERROR, $check->status());
    }

    public function testDetectsStepNotInRequiredSteps(): void
    {
        $template = new ProcessTemplate(
            key: 'demo',
            steps: [new ProcessTemplateStep('a'), new ProcessTemplateStep('b')],
            requiredStepKeys: ['a'],
        );

        $check = $this->check($this->analyzer()->analyze($template), 'steps_not_in_required');

        self::assertSame(['b'], $check->items);
        self::assertSame(TemplateAssistantCheck::STATUS_WARNING, $check->status());
    }

    public function testStepNotInRequiredIsNotFlaggedWhenNoRequiredStepsDeclared(): void
    {
        $template = new ProcessTemplate(
            key: 'demo',
            steps: [new ProcessTemplateStep('a'), new ProcessTemplateStep('b')],
        );

        // Empty required_steps means "all steps required" - must not flag every step.
        self::assertFalse($this->check($this->analyzer()->analyze($template), 'steps_not_in_required')->hasItems());
    }

    public function testDetectsDuplicateTransition(): void
    {
        $template = new ProcessTemplate(
            key: 'demo',
            steps: [new ProcessTemplateStep('a'), new ProcessTemplateStep('b')],
            transitions: [
                new ProcessTemplateTransition('a', 'b'),
                new ProcessTemplateTransition('a', 'b'),
            ],
        );

        $check = $this->check($this->analyzer()->analyze($template), 'duplicate_transitions');

        self::assertTrue($check->hasItems());
        self::assertSame(['a → b'], $check->items);
        self::assertSame(TemplateAssistantCheck::STATUS_WARNING, $check->status());
    }

    public function testDetectsDuplicateStepKeys(): void
    {
        $template = new ProcessTemplate(
            key: 'demo',
            steps: [new ProcessTemplateStep('a'), new ProcessTemplateStep('a')],
        );

        $check = $this->check($this->analyzer()->analyze($template), 'duplicate_steps');

        self::assertSame(['a'], $check->items);
        self::assertSame(TemplateAssistantCheck::STATUS_ERROR, $check->status());
    }

    public function testCleanFlatTemplateIsOkAndRunsStructuralChecks(): void
    {
        $template = new ProcessTemplate(
            key: 'demo',
            initialStepKey: 'a',
            steps: [new ProcessTemplateStep('a'), new ProcessTemplateStep('b')],
            transitions: [new ProcessTemplateTransition('a', 'b')],
            requiredStepKeys: ['a', 'b'],
        );

        $view = $this->analyzer()->analyze($template);

        self::assertTrue($view->structuralChecksApplicable);
        self::assertNull($view->structuralNote);
        // Initial step "a" is exempt from incoming; "b" has incoming. "b" has no
        // outgoing -> a single warning, so overall is warning (not error).
        self::assertFalse($this->check($view, 'steps_without_incoming')->hasItems());
        self::assertSame(['b'], $this->check($view, 'steps_without_outgoing')->items);
        self::assertSame(TemplateAssistantCheck::STATUS_WARNING, $view->overallStatus);
    }

    public function testStructuralChecksAreSkippedWithDecisionPoints(): void
    {
        $template = new ProcessTemplate(
            key: 'demo',
            steps: [new ProcessTemplateStep('a'), new ProcessTemplateStep('b')],
            transitions: [new ProcessTemplateTransition('a', 'b')],
            decisionPoints: [new ProcessTemplateDecisionPoint('d', 'a', [], [])],
        );

        $view = $this->analyzer()->analyze($template);

        self::assertFalse($view->structuralChecksApplicable);
        self::assertNotNull($view->structuralNote);
        self::assertNull($this->maybeCheck($view, 'steps_without_incoming'));
        self::assertNull($this->maybeCheck($view, 'steps_without_outgoing'));
    }

    public function testPassesMetadataAndFilePathThrough(): void
    {
        $template = new ProcessTemplate(
            key: 'demo',
            version: '2.0',
            name: 'Demo-Prozess',
            steps: [new ProcessTemplateStep('a', 'Erster')],
            sourceSystem: 'amagno',
        );

        $view = $this->analyzer()->analyze($template, '/srv/templates/demo.yaml');

        self::assertSame('2.0', $view->version);
        self::assertSame('Demo-Prozess', $view->name);
        self::assertSame('amagno', $view->sourceSystem);
        self::assertSame('/srv/templates/demo.yaml', $view->filePath);
        self::assertSame('Erster', $view->steps[0]['name']);
        self::assertSame(1, $view->steps[0]['position']);
    }

    private function check(TemplateAssistantView $view, string $id): TemplateAssistantCheck
    {
        $check = $this->maybeCheck($view, $id);
        if ($check === null) {
            self::fail(sprintf('Consistency check "%s" not found.', $id));
        }

        return $check;
    }

    private function maybeCheck(TemplateAssistantView $view, string $id): ?TemplateAssistantCheck
    {
        foreach ($view->checks as $check) {
            if ($check->id === $id) {
                return $check;
            }
        }

        return null;
    }
}
