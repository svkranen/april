<?php

namespace App\Tests\Command;

use App\Command\AprilWizardShowCommand;
use App\Wizard\WizardDefinitionLoader;
use App\Wizard\WizardDefinitionRenderer;
use App\Wizard\WizardLinkResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class AprilWizardShowCommandTest extends TestCase
{
    public function testShowsFirstInsightWizard(): void
    {
        $tester = new CommandTester(new AprilWizardShowCommand(
            new WizardDefinitionLoader(dirname(__DIR__, 2).'/config/april/wizards'),
            new WizardDefinitionRenderer(new WizardLinkResolver($this->urlGenerator()))
        ));

        $exitCode = $tester->execute(['key' => 'first-insight']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Name: First Insight', $tester->getDisplay());
        self::assertStringContainsString('Scenario:', $tester->getDisplay());
        self::assertStringContainsString('Prerequisites:', $tester->getDisplay());
        self::assertStringContainsString('Open Journey', $tester->getDisplay());
        self::assertStringContainsString('app_intelligence_documents_show', $tester->getDisplay());
        self::assertStringContainsString('/app/intelligence/documents/10000000-0000-4000-8000-000000000004', $tester->getDisplay());
        self::assertStringContainsString('all_steps_completed', $tester->getDisplay());
    }

    private function urlGenerator(): UrlGenerator
    {
        $routes = new RouteCollection();
        $routes->add('app_templates_documents', new Route('/app/templates/{key}/documents'));
        $routes->add('app_intelligence_documents_show', new Route('/app/intelligence/documents/{documentUuid}'));
        $routes->add('app_templates_graph', new Route('/app/templates/{key}/graph'));

        return new UrlGenerator($routes, new RequestContext());
    }
}
