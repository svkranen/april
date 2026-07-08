<?php

namespace App\Tests\Wizard;

use App\Intelligence\Application\ProcessTemplateProvider;
use App\Intelligence\Domain\ProcessTemplate;
use App\Wizard\NullWizardProgressStore;
use App\Wizard\WizardDefinitionLoader;
use App\Wizard\WizardDefinitionRenderer;
use App\Wizard\WizardLinkResolver;
use App\Wizard\WizardCompletionChecker;
use App\Wizard\WizardPrerequisiteChecker;
use App\Wizard\WizardProgressReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class WizardDefinitionRendererTest extends TestCase
{
    public function testRendersFirstInsightDefinition(): void
    {
        $wizard = (new WizardDefinitionLoader(dirname(__DIR__, 2).'/config/april/wizards'))->load('first-insight');

        $output = (new WizardDefinitionRenderer())->render($wizard);

        self::assertStringContainsString('Name: First Insight', $output);
        self::assertStringContainsString('Description: Guide new users through the Incident Management demo.', $output);
        self::assertStringContainsString('Audience:', $output);
        self::assertStringContainsString('- developer', $output);
        self::assertStringContainsString('Scenario:', $output);
        self::assertStringContainsString('- key: incident-management', $output);
        self::assertStringContainsString('Prerequisites:', $output);
        self::assertStringContainsString('demo_fixtures_loaded', $output);
        self::assertStringContainsString('Steps:', $output);
        self::assertStringContainsString('1. Welcome to APRIL (welcome)', $output);
        self::assertStringContainsString('Concepts:', $output);
        self::assertStringContainsString('Links:', $output);
        self::assertStringContainsString('Completion:', $output);
        self::assertStringContainsString('route_visited', $output);
    }

    public function testRendersResolvedRoutePathsForLinks(): void
    {
        $wizard = (new WizardDefinitionLoader(dirname(__DIR__, 2).'/config/april/wizards'))->load('first-insight');

        $output = (new WizardDefinitionRenderer(new WizardLinkResolver($this->urlGenerator())))->render($wizard);

        self::assertStringContainsString('path=/app/templates/incident-management/documents?withFindings=1', $output);
        self::assertStringContainsString('path=/app/intelligence/documents/10000000-0000-4000-8000-000000000004', $output);
        self::assertStringContainsString('path=/app/templates/incident-management/graph?withFindings=1', $output);
    }

    public function testRendersPrerequisiteStatuses(): void
    {
        $wizard = (new WizardDefinitionLoader(dirname(__DIR__, 2).'/config/april/wizards'))->load('first-insight');

        $output = (new WizardDefinitionRenderer(
            new WizardLinkResolver($this->urlGenerator()),
            new WizardPrerequisiteChecker($this->provider(), $this->urlGenerator())
        ))->render($wizard);

        self::assertStringContainsString('key=app_available, type=route, route=app_templates_index, required=1, status=ok', $output);
        self::assertStringContainsString('key=demo_template_available, type=process_template, process_key=incident-management, required=1, status=ok', $output);
        self::assertStringContainsString('key=demo_fixtures_loaded, type=fixture_scenario, scenario=incident-management, required=1, status=warning', $output);
    }

    public function testRendersCompletionStatuses(): void
    {
        $wizard = (new WizardDefinitionLoader(dirname(__DIR__, 2).'/config/april/wizards'))->load('first-insight');

        $output = (new WizardDefinitionRenderer(
            new WizardLinkResolver($this->urlGenerator()),
            new WizardPrerequisiteChecker($this->provider(), $this->urlGenerator()),
            new WizardCompletionChecker()
        ))->render($wizard);

        self::assertStringContainsString('type=step_acknowledged, status=unknown, message=No Wizard runtime or persistence exists yet.', $output);
        self::assertStringContainsString('type=route_visited, route=app_templates_documents, status=unknown, message=Route visits are not tracked yet.', $output);
        self::assertStringContainsString('type=manual, note=Confirm that the Decision Rule Violation is visible., status=unknown, message=Manual completion is not executable in the MVP.', $output);
    }

    public function testRendersProgressStatuses(): void
    {
        $wizard = (new WizardDefinitionLoader(dirname(__DIR__, 2).'/config/april/wizards'))->load('first-insight');

        $output = (new WizardDefinitionRenderer(
            progressReader: new WizardProgressReader(new NullWizardProgressStore())
        ))->render($wizard);

        self::assertStringContainsString('Progress:', $output);
        self::assertStringContainsString('- status: unknown', $output);
        self::assertStringContainsString('- message: Wizard progress is not persisted yet.', $output);
        self::assertStringContainsString('- step: welcome', $output);
    }

    public function testRendersWarningForUnsupportedCompletionType(): void
    {
        $directory = $this->createDirectory([
            'unsupported-completion.yaml' => <<<'YAML'
key: unsupported-completion
version: "1.0"
name: "Unsupported Completion"
steps:
  - key: inspect
    title: "Inspect"
    completion:
      type: finding_present
YAML,
        ]);
        $wizard = (new WizardDefinitionLoader($directory))->load('unsupported-completion');

        try {
            $output = (new WizardDefinitionRenderer(completionChecker: new WizardCompletionChecker()))->render($wizard);
        } finally {
            $this->removeDirectory($directory);
        }

        self::assertStringContainsString('type=finding_present, status=warning, message=Unsupported completion type "finding_present".', $output);
    }

    public function testRendersWarningForUnresolvedRoute(): void
    {
        $directory = $this->createDirectory([
            'broken-link.yaml' => <<<'YAML'
key: broken-link
version: "1.0"
name: "Broken Link"
steps:
  - key: open
    title: "Open"
    links:
      - key: missing
        label: "Missing"
        route: app_missing_route
YAML,
        ]);
        $wizard = (new WizardDefinitionLoader($directory))->load('broken-link');

        try {
            $output = (new WizardDefinitionRenderer(new WizardLinkResolver($this->urlGenerator())))->render($wizard);
        } finally {
            $this->removeDirectory($directory);
        }

        self::assertStringContainsString('warning=route "app_missing_route" could not be resolved', $output);
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

    /**
     * @param array<string, string> $files
     */
    private function createDirectory(array $files): string
    {
        $directory = sys_get_temp_dir().'/april-wizard-renderer-'.bin2hex(random_bytes(4));
        mkdir($directory);

        foreach ($files as $name => $content) {
            file_put_contents($directory.'/'.$name, $content);
        }

        return $directory;
    }

    private function removeDirectory(string $directory): void
    {
        foreach (glob($directory.'/*') ?: [] as $path) {
            unlink($path);
        }

        rmdir($directory);
    }
}
