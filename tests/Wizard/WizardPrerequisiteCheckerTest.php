<?php

namespace App\Tests\Wizard;

use App\Intelligence\Application\ProcessTemplateProvider;
use App\Intelligence\Domain\ProcessTemplate;
use App\Wizard\WizardPrerequisiteChecker;
use App\Wizard\WizardPrerequisiteCheckResult;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class WizardPrerequisiteCheckerTest extends TestCase
{
    public function testRoutePrerequisiteIsOkWhenRouteResolves(): void
    {
        $result = $this->checker()->check([
            'key' => 'app_available',
            'type' => 'route',
            'route' => 'app_templates_index',
        ]);

        self::assertSame('app_available', $result->key);
        self::assertSame('route', $result->type);
        self::assertSame(WizardPrerequisiteCheckResult::STATUS_OK, $result->status);
        self::assertStringContainsString('/app/templates', $result->message);
    }

    public function testRoutePrerequisiteIsMissingWhenRouteCannotResolve(): void
    {
        $result = $this->checker()->check([
            'key' => 'missing_route',
            'type' => 'route',
            'route' => 'app_missing',
        ]);

        self::assertSame(WizardPrerequisiteCheckResult::STATUS_MISSING, $result->status);
        self::assertStringContainsString('could not be resolved', $result->message);
    }

    public function testProcessTemplatePrerequisiteIsOkWhenTemplateExists(): void
    {
        $result = $this->checker(['incident-management' => new ProcessTemplate('incident-management')])->check([
            'key' => 'demo_template_available',
            'type' => 'process_template',
            'process_key' => 'incident-management',
        ]);

        self::assertSame(WizardPrerequisiteCheckResult::STATUS_OK, $result->status);
        self::assertStringContainsString('is available', $result->message);
    }

    public function testProcessTemplatePrerequisiteIsMissingWhenTemplateDoesNotExist(): void
    {
        $result = $this->checker()->check([
            'key' => 'demo_template_available',
            'type' => 'process_template',
            'process_key' => 'incident-management',
        ]);

        self::assertSame(WizardPrerequisiteCheckResult::STATUS_MISSING, $result->status);
        self::assertStringContainsString('was not found', $result->message);
    }

    public function testFixtureScenarioPrerequisiteIsWarningInMvp(): void
    {
        $result = $this->checker()->check([
            'key' => 'demo_fixtures_loaded',
            'type' => 'fixture_scenario',
            'scenario' => 'incident-management',
        ]);

        self::assertSame(WizardPrerequisiteCheckResult::STATUS_WARNING, $result->status);
        self::assertStringContainsString('cannot be verified reliably', $result->message);
    }

    /**
     * @param array<string, ProcessTemplate> $templates
     */
    private function checker(array $templates = []): WizardPrerequisiteChecker
    {
        return new WizardPrerequisiteChecker($this->provider($templates), $this->urlGenerator());
    }

    /**
     * @param array<string, ProcessTemplate> $templates
     */
    private function provider(array $templates): ProcessTemplateProvider
    {
        return new class($templates) implements ProcessTemplateProvider {
            /** @param array<string, ProcessTemplate> $templates */
            public function __construct(private readonly array $templates)
            {
            }

            public function findByProcessKey(string $processKey): ?ProcessTemplate
            {
                return $this->templates[$processKey] ?? null;
            }
        };
    }

    private function urlGenerator(): UrlGenerator
    {
        $routes = new RouteCollection();
        $routes->add('app_templates_index', new Route('/app/templates'));

        return new UrlGenerator($routes, new RequestContext());
    }
}
