<?php

namespace App\Tests\Command;

use App\Command\AprilWizardListCommand;
use App\Wizard\WizardDefinitionLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class AprilWizardListCommandTest extends TestCase
{
    public function testListsFirstInsightWizard(): void
    {
        $loader = new WizardDefinitionLoader(dirname(__DIR__, 2).'/config/april/wizards');
        $tester = new CommandTester(new AprilWizardListCommand($loader));

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('first-insight', $tester->getDisplay());
        self::assertStringContainsString('1.0', $tester->getDisplay());
        self::assertStringContainsString('5', $tester->getDisplay());
        self::assertStringContainsString('valid', $tester->getDisplay());
    }
}
