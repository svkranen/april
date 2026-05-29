<?php

namespace App\Tests\Command;

use App\Command\IntelligenceTemplateSuggestFromDocumentCommand;
use App\Intelligence\Application\ProcessTemplateSuggestionService;
use App\Intelligence\Domain\ProcessEvent;
use App\Intelligence\Infrastructure\Process\InMemoryDocumentTimelineProvider;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;

class IntelligenceTemplateSuggestFromDocumentCommandTest extends TestCase
{
    public function testSuggestsYamlWithStepsAndTransitionFromEvents(): void
    {
        $tester = new CommandTester($this->command());

        $exitCode = $tester->execute([
            'documentUuid' => 'uuid-1',
            'processKey' => 'eingangsrechnung',
            '--document-version' => '1',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('required: []', $tester->getDisplay());
        $template = Yaml::parse($tester->getDisplay());

        self::assertSame('eingangsrechnung', $template['key']);
        self::assertSame('draft', $template['version']);
        self::assertSame(
            [
                ['key' => 'eingang', 'name' => 'Eingang'],
                ['key' => 'pruefung', 'name' => 'Pruefung'],
            ],
            $template['steps']
        );
        self::assertSame(
            [
                ['from' => 'eingang', 'to' => 'pruefung'],
            ],
            $template['transitions']
        );
        self::assertSame(['required' => []], $template['context_profile']);
    }

    public function testDeduplicatesDirectDuplicateSteps(): void
    {
        $tester = new CommandTester($this->command());

        $tester->execute([
            'documentUuid' => 'uuid-1',
            'processKey' => 'eingangsrechnung',
            '--document-version' => '1',
        ]);

        $template = Yaml::parse($tester->getDisplay());

        self::assertSame(['eingang', 'pruefung'], array_column($template['steps'], 'key'));
        self::assertCount(1, $template['transitions']);
    }

    public function testVersionOptionFiltersEvents(): void
    {
        $tester = new CommandTester($this->command());

        $tester->execute([
            'documentUuid' => 'uuid-1',
            'processKey' => 'eingangsrechnung',
            '--document-version' => '2',
        ]);

        $template = Yaml::parse($tester->getDisplay());

        self::assertSame(['eingang', 'freigabe'], array_column($template['steps'], 'key'));
        self::assertSame([['from' => 'eingang', 'to' => 'freigabe']], $template['transitions']);
    }

    public function testWritesYamlToOutputFile(): void
    {
        $path = sys_get_temp_dir() . '/amagno-template-suggest-' . bin2hex(random_bytes(6)) . '/eingangsrechnung.yaml';
        $tester = new CommandTester($this->command());

        $exitCode = $tester->execute([
            'documentUuid' => 'uuid-1',
            'processKey' => 'eingangsrechnung',
            '--output' => $path,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertFileExists($path);

        $template = Yaml::parseFile($path);
        self::assertSame(['eingang', 'freigabe'], array_column($template['steps'], 'key'));

        unlink($path);
        rmdir(dirname($path));
    }

    public function testDoesNotOverwriteOutputFileWithoutForce(): void
    {
        $path = sys_get_temp_dir() . '/amagno-template-suggest-' . bin2hex(random_bytes(6)) . '/eingangsrechnung.yaml';
        mkdir(dirname($path), 0775, true);
        file_put_contents($path, "existing: true\n");
        $tester = new CommandTester($this->command());

        $exitCode = $tester->execute([
            'documentUuid' => 'uuid-1',
            'processKey' => 'eingangsrechnung',
            '--output' => $path,
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Output file already exists', $tester->getDisplay());
        self::assertSame("existing: true\n", file_get_contents($path));

        unlink($path);
        rmdir(dirname($path));
    }

    public function testOverwritesOutputFileWithForce(): void
    {
        $path = sys_get_temp_dir() . '/amagno-template-suggest-' . bin2hex(random_bytes(6)) . '/eingangsrechnung.yaml';
        mkdir(dirname($path), 0775, true);
        file_put_contents($path, "existing: true\n");
        $tester = new CommandTester($this->command());

        $exitCode = $tester->execute([
            'documentUuid' => 'uuid-1',
            'processKey' => 'eingangsrechnung',
            '--output' => $path,
            '--force' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Template suggestion written', $tester->getDisplay());
        self::assertStringContainsString('required: []', (string) file_get_contents($path));

        $template = Yaml::parseFile($path);
        self::assertSame(['eingang', 'freigabe'], array_column($template['steps'], 'key'));

        unlink($path);
        rmdir(dirname($path));
    }

    private function command(): IntelligenceTemplateSuggestFromDocumentCommand
    {
        return new IntelligenceTemplateSuggestFromDocumentCommand(
            new ProcessTemplateSuggestionService(
                new InMemoryDocumentTimelineProvider(
                    [],
                    [
                        $this->event(1, 'evt-2', 'pruefung', 1, '2026-05-29T10:00:00+00:00'),
                        $this->event(2, 'evt-1', 'eingang', 1, '2026-05-29T09:00:00+00:00'),
                        $this->event(3, 'evt-1-duplicate-step', 'eingang', 1, '2026-05-29T09:05:00+00:00'),
                        $this->event(4, 'evt-3', 'eingang', 2, '2026-05-29T11:00:00+00:00'),
                        $this->event(5, 'evt-4', 'freigabe', 2, '2026-05-29T12:00:00+00:00'),
                        $this->event(6, 'evt-other-process', 'archiv', 2, '2026-05-29T13:00:00+00:00', 'anderer-prozess'),
                    ]
                )
            )
        );
    }

    private function event(
        int $id,
        string $externalEventKey,
        string $stepKey,
        int $documentVersion,
        string $occurredAt,
        string $processKey = 'eingangsrechnung'
    ): ProcessEvent {
        $time = new DateTimeImmutable($occurredAt);

        return new ProcessEvent(
            $id,
            $externalEventKey,
            'amagno',
            $processKey,
            $stepKey,
            $stepKey,
            'doc-1',
            'uuid-1',
            $documentVersion,
            'user-1',
            $time,
            $time,
            '{}',
            '{}',
            $documentVersion
        );
    }
}
