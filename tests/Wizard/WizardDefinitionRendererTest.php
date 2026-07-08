<?php

namespace App\Tests\Wizard;

use App\Wizard\WizardDefinitionLoader;
use App\Wizard\WizardDefinitionRenderer;
use PHPUnit\Framework\TestCase;

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
}
