<?php

namespace App\Tests\Command;

use App\Command\IntelligenceTemplateListCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class IntelligenceTemplateListCommandTest extends TestCase
{
    public function testListsYamlTemplatesAsText(): void
    {
        $directory = $this->createTemplateDirectory([
            'invoice.yaml' => "key: invoice\nversion: '1'\nsteps: []\n",
            'order.yaml' => "key: order\nversion: draft\nsteps: []\n",
        ]);
        $tester = new CommandTester(new IntelligenceTemplateListCommand($directory));

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('invoice', $tester->getDisplay());
        self::assertStringContainsString('order', $tester->getDisplay());
        self::assertStringContainsString($directory.'/invoice.yaml', $tester->getDisplay());

        $this->removeDirectory($directory);
    }

    public function testListsYamlTemplatesAsJson(): void
    {
        $directory = $this->createTemplateDirectory([
            'invoice.yaml' => "key: invoice\nversion: '1'\nsteps: []\n",
        ]);
        $tester = new CommandTester(new IntelligenceTemplateListCommand($directory));

        $exitCode = $tester->execute([
            '--format' => 'json',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(
            [
                [
                    'key' => 'invoice',
                    'version' => '1',
                    'path' => $directory.'/invoice.yaml',
                ],
            ],
            $data['templates']
        );
        self::assertSame([], $data['warnings']);

        $this->removeDirectory($directory);
    }

    public function testInvalidTemplatesAreReportedAsWarnings(): void
    {
        $directory = $this->createTemplateDirectory([
            'invoice.yaml' => "key: invoice\nversion: '1'\nsteps: []\n",
            'invalid.yaml' => "version: '1'\nsteps: []\n",
        ]);
        $tester = new CommandTester(new IntelligenceTemplateListCommand($directory));

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('invoice', $tester->getDisplay());
        self::assertStringContainsString('Warnings:', $tester->getDisplay());
        self::assertStringContainsString('invalid.yaml: Template key is missing.', $tester->getDisplay());

        $this->removeDirectory($directory);
    }

    /**
     * @param array<string, string> $files
     */
    private function createTemplateDirectory(array $files): string
    {
        $directory = sys_get_temp_dir().'/intelligence-template-list-'.bin2hex(random_bytes(4));
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
