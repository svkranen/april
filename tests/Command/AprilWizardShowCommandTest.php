<?php

namespace App\Tests\Command;

use App\Command\AprilWizardShowCommand;
use App\Wizard\WizardDefinitionLoader;
use App\Wizard\WizardDefinitionRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class AprilWizardShowCommandTest extends TestCase
{
    public function testShowsFirstInsightWizard(): void
    {
        $tester = new CommandTester(new AprilWizardShowCommand(
            new WizardDefinitionLoader(dirname(__DIR__, 2).'/config/april/wizards'),
            new WizardDefinitionRenderer()
        ));

        $exitCode = $tester->execute(['key' => 'first-insight']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Name: First Insight', $tester->getDisplay());
        self::assertStringContainsString('Scenario:', $tester->getDisplay());
        self::assertStringContainsString('Prerequisites:', $tester->getDisplay());
        self::assertStringContainsString('Open Journey', $tester->getDisplay());
        self::assertStringContainsString('app_intelligence_documents_show', $tester->getDisplay());
        self::assertStringContainsString('all_steps_completed', $tester->getDisplay());
    }
}
