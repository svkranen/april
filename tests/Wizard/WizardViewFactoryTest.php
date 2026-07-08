<?php

namespace App\Tests\Wizard;

use App\Intelligence\Application\ProcessTemplateProvider;
use App\Intelligence\Domain\ProcessTemplate;
use App\Wizard\NullWizardProgressStore;
use App\Wizard\WizardCompletionChecker;
use App\Wizard\WizardDefinition;
use App\Wizard\WizardDefinitionLoader;
use App\Wizard\WizardLinkResolver;
use App\Wizard\WizardPrerequisiteCheckResult;
use App\Wizard\WizardPrerequisiteChecker;
use App\Wizard\WizardProgressReader;
use App\Wizard\WizardStepDefinition;
use App\Wizard\WizardViewFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class WizardViewFactoryTest extends TestCase
{
    public function testCreatesViewForWizardWithoutSteps(): void
    {
        $wizard = new WizardDefinition(
            'empty',
            '1.0',
            'Empty Wizard',
            [],
            '/tmp/empty.yaml',
            'No steps yet.',
            [
                'audience' => ['developer'],
                'scenario' => ['key' => 'none'],
            ]
        );

        $view = $this->factory()->create($wizard);

        self::assertSame('empty', $view->key);
        self::assertSame('1.0', $view->version);
        self::assertSame('Empty Wizard', $view->title);
        self::assertSame('No steps yet.', $view->description);
        self::assertSame(['developer'], $view->audience);
        self::assertSame(['key' => 'none'], $view->scenario);
        self::assertSame([], $view->concepts);
        self::assertSame([], $view->steps);
    }

    public function testCreatesViewForWizardWithMultipleSteps(): void
    {
        $view = $this->factory()->create($this->firstInsightWizard());

        self::assertSame('first-insight', $view->key);
        self::assertSame('First Insight', $view->title);
        self::assertCount(5, $view->steps);
        self::assertSame('welcome', $view->steps[0]->key);
        self::assertSame('Welcome to APRIL', $view->steps[0]->title);
        self::assertContains('item', $view->concepts);
        self::assertContains('journey', $view->concepts);
        self::assertContains('finding', $view->concepts);
    }

    public function testPrerequisitesAreCarriedIntoViewWithStatuses(): void
    {
        $view = $this->factory()->create($this->firstInsightWizard());

        self::assertCount(3, $view->prerequisites);
        self::assertSame('app_available', $view->prerequisites[0]['key']);
        self::assertSame(WizardPrerequisiteCheckResult::STATUS_OK, $view->prerequisites[0]['status']);
        self::assertSame('demo_template_available', $view->prerequisites[1]['key']);
        self::assertSame(WizardPrerequisiteCheckResult::STATUS_OK, $view->prerequisites[1]['status']);
        self::assertSame('demo_fixtures_loaded', $view->prerequisites[2]['key']);
        self::assertSame(WizardPrerequisiteCheckResult::STATUS_WARNING, $view->prerequisites[2]['status']);
    }

    public function testCompletionIsCarriedIntoStepViewsWithStatuses(): void
    {
        $view = $this->factory()->create($this->firstInsightWizard());

        self::assertSame('step_acknowledged', $view->steps[0]->completion[0]['type']);
        self::assertSame('unknown', $view->steps[0]->completion[0]['status']);
        self::assertSame('route_visited', $view->steps[1]->completion[0]['type']);
        self::assertSame('unknown', $view->steps[1]->completion[0]['status']);
        self::assertSame('manual', $view->steps[3]->completion[0]['type']);
        self::assertSame('unknown', $view->steps[3]->completion[0]['status']);
    }

    public function testProgressIsCarriedIntoWizardAndStepViews(): void
    {
        $view = $this->factory()->create($this->firstInsightWizard());

        self::assertSame('unknown', $view->progress['status']);
        self::assertSame('Wizard progress is not persisted yet.', $view->progress['message']);
        self::assertSame('unknown', $view->steps[0]->progress['status']);
        self::assertSame('Wizard progress is not persisted yet.', $view->steps[0]->progress['message']);
        self::assertSame('welcome', $view->steps[0]->progress['step']);
    }

    public function testLinksAreCarriedIntoStepViewsWithResolvedPaths(): void
    {
        $view = $this->factory()->create($this->firstInsightWizard());

        self::assertSame('items_with_findings', $view->steps[1]->links[0]['key']);
        self::assertSame('/app/templates/incident-management/documents?withFindings=1', $view->steps[1]->links[0]['path']);
        self::assertSame('deviation_journey', $view->steps[2]->links[0]['key']);
        self::assertSame('/app/intelligence/documents/10000000-0000-4000-8000-000000000004', $view->steps[2]->links[0]['path']);
        self::assertSame('graph_with_findings', $view->steps[4]->links[0]['key']);
        self::assertSame('/app/templates/incident-management/graph?withFindings=1', $view->steps[4]->links[0]['path']);
    }

    public function testStepWithoutCompletionKeepsReadableUnknownCompletion(): void
    {
        $wizard = new WizardDefinition(
            'minimal',
            '1.0',
            'Minimal',
            [
                new WizardStepDefinition('read', 'Read'),
            ],
            '/tmp/minimal.yaml'
        );

        $view = $this->factory()->create($wizard);

        self::assertSame('none', $view->steps[0]->completion[0]['type']);
        self::assertSame('unknown', $view->steps[0]->completion[0]['status']);
        self::assertSame('No completion rule is defined for this step.', $view->steps[0]->completion[0]['message']);
    }

    private function firstInsightWizard(): WizardDefinition
    {
        return (new WizardDefinitionLoader(dirname(__DIR__, 2).'/config/april/wizards'))->load('first-insight');
    }

    private function factory(): WizardViewFactory
    {
        return new WizardViewFactory(
            new WizardLinkResolver($this->urlGenerator()),
            new WizardPrerequisiteChecker($this->provider(), $this->urlGenerator()),
            new WizardCompletionChecker(),
            new WizardProgressReader(new NullWizardProgressStore())
        );
    }

    private function urlGenerator(): UrlGenerator
    {
        $routes = new RouteCollection();
        $routes->add('app_templates_index', new Route('/app/templates'));
        $routes->add('app_templates_documents', new Route('/app/templates/{key}/documents'));
        $routes->add('app_intelligence_documents_show', new Route('/app/intelligence/documents/{documentUuid}'));
        $routes->add('app_templates_graph', new Route('/app/templates/{key}/graph'));

        return new UrlGenerator($routes, new RequestContext());
    }

    private function provider(): ProcessTemplateProvider
    {
        return new class implements ProcessTemplateProvider {
            public function findByProcessKey(string $processKey): ?ProcessTemplate
            {
                return $processKey === 'incident-management' ? new ProcessTemplate($processKey) : null;
            }
        };
    }
}
