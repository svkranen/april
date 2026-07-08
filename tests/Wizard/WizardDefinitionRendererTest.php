<?php

namespace App\Tests\Wizard;

use App\Wizard\WizardDefinitionLoader;
use App\Wizard\WizardDefinitionRenderer;
use App\Wizard\WizardLinkResolver;
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
        self::assertStringContainsString('finding_present', $output);
    }

    public function testRendersResolvedRoutePathsForLinks(): void
    {
        $wizard = (new WizardDefinitionLoader(dirname(__DIR__, 2).'/config/april/wizards'))->load('first-insight');

        $output = (new WizardDefinitionRenderer(new WizardLinkResolver($this->urlGenerator())))->render($wizard);

        self::assertStringContainsString('path=/app/templates/incident-management/documents?withFindings=1', $output);
        self::assertStringContainsString('path=/app/intelligence/documents/10000000-0000-4000-8000-000000000004', $output);
        self::assertStringContainsString('path=/app/templates/incident-management/graph?withFindings=1', $output);
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
        $routes->add('app_templates_documents', new Route('/app/templates/{key}/documents'));
        $routes->add('app_intelligence_documents_show', new Route('/app/intelligence/documents/{documentUuid}'));
        $routes->add('app_templates_graph', new Route('/app/templates/{key}/graph'));

        return new UrlGenerator($routes, new RequestContext());
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
