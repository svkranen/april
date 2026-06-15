<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\TemplateDetailView;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateAccessProbe;
use App\Intelligence\Domain\ProcessTemplateDecisionPoint;
use App\Intelligence\Domain\ProcessTemplateDecisionRule;
use App\Intelligence\Domain\ProcessTemplateFieldMapping;
use App\Intelligence\Domain\ProcessTemplateManualAccessTest;
use App\Intelligence\Domain\ProcessTemplateSignCheck;
use App\Intelligence\Domain\ProcessTemplateStep;
use App\Intelligence\Domain\ProcessTemplateTransition;
use App\Intelligence\Domain\ProcessTemplateVisibilityCheck;
use App\Intelligence\Domain\ProcessTemplateVisibilityProfile;
use App\Intelligence\Domain\ProcessTemplateVisibilityProfileResolver;
use PHPUnit\Framework\TestCase;

class TemplateDetailViewTest extends TestCase
{
    public function testMapsTemplateIntoReadableRowsAndCounts(): void
    {
        $view = TemplateDetailView::fromTemplate($this->template());

        self::assertSame('invoice', $view->key);
        self::assertSame('2.0', $view->version);
        self::assertSame('amagno', $view->sourceSystem);
        self::assertSame('Rechnungslauf', $view->name);
        self::assertSame(['A'], $view->requiredStepKeys);

        // Steps + visibility-check phase counts.
        self::assertCount(2, $view->steps);
        self::assertSame('A', $view->steps[0]['key']);
        self::assertSame(1, $view->steps[0]['beforeChecks']);
        self::assertSame(2, $view->steps[0]['afterChecks']);
        self::assertSame(0, $view->steps[1]['beforeChecks']);
        self::assertSame(0, $view->steps[1]['afterChecks']);

        // Transitions (including parallel group target).
        self::assertCount(2, $view->transitions);
        self::assertSame('A', $view->transitions[0]['from']);
        self::assertSame('B', $view->transitions[0]['to']);
        self::assertNull($view->transitions[1]['to']);
        self::assertSame('grp', $view->transitions[1]['parallelGroup']);

        // Decision points: rule count + distinct outcomes.
        self::assertCount(1, $view->decisionPoints);
        self::assertSame('decide', $view->decisionPoints[0]['key']);
        self::assertSame('A', $view->decisionPoints[0]['after']);
        self::assertSame(['standort'], $view->decisionPoints[0]['requiredFields']);
        self::assertSame(3, $view->decisionPoints[0]['ruleCount']);
        self::assertSame(['B', 'Parallelgruppe: grp'], $view->decisionPoints[0]['outcomes']);

        // Field mapping + sign checks.
        self::assertSame('standort', $view->fieldMappings[0]['fieldKey']);
        self::assertSame('tag', $view->fieldMappings[0]['source']);
        self::assertCount(1, $view->signChecks);
        self::assertSame('four_eyes', $view->signChecks[0]['key']);

        // Access summary counts.
        self::assertSame([
            'accessProbes' => 2,
            'visibilityProfiles' => 1,
            'visibilityProfileResolvers' => 1,
            'manualAccessTests' => 1,
            'beforeVisibilityChecks' => 1,
            'afterVisibilityChecks' => 2,
            'totalVisibilityChecks' => 3,
        ], $view->accessSummary);
    }

    private function template(): ProcessTemplate
    {
        $stepA = new ProcessTemplateStep(
            'A',
            'Rechnungen prüfen',
            'normal',
            [new ProcessTemplateVisibilityCheck('init', 'before')],
            [
                new ProcessTemplateVisibilityCheck('route', 'after'),
                new ProcessTemplateVisibilityCheck('external', 'after'),
            ]
        );
        $stepB = new ProcessTemplateStep('B', 'Buchen', 'normal');

        $decision = new ProcessTemplateDecisionPoint('decide', 'A', ['standort'], [
            new ProcessTemplateDecisionRule(null, 'B'),
            new ProcessTemplateDecisionRule(null, 'B'),
            new ProcessTemplateDecisionRule(null, null, false, 'grp'),
        ]);

        return new ProcessTemplate(
            key: 'invoice',
            version: '2.0',
            name: 'Rechnungslauf',
            steps: [$stepA, $stepB],
            transitions: [
                new ProcessTemplateTransition('A', 'B'),
                new ProcessTemplateTransition('B', null, 'grp'),
            ],
            contextProfileRequiredFields: ['standort'],
            fieldMappings: ['standort' => new ProcessTemplateFieldMapping('standort', 'tag', 'Standort', '42', 'string', 'snapshot_required')],
            decisionPoints: [$decision],
            requiredStepKeys: ['A'],
            signChecks: [new ProcessTemplateSignCheck('four_eyes', 'required_set', 'actual_set')],
            accessProbes: [
                'a' => new ProcessTemplateAccessProbe('a', 'amagno', 'amagno_magnet_documents'),
                'b' => new ProcessTemplateAccessProbe('b', 'amagno', 'amagno_magnet_documents'),
            ],
            visibilityProfiles: ['p' => new ProcessTemplateVisibilityProfile('p', ['a'], ['b'])],
            visibilityProfileResolvers: ['r' => new ProcessTemplateVisibilityProfileResolver('r', 'standort', ['A' => 'p'])],
            manualAccessTests: [new ProcessTemplateManualAccessTest('m', 'Test')],
            sourceSystem: 'amagno'
        );
    }
}
