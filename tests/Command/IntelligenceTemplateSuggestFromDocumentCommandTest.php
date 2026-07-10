<?php

namespace App\Tests\Command;

use App\Command\IntelligenceTemplateSuggestFromDocumentCommand;
use App\Intelligence\Application\JourneyTemplateSuggestionService;
use App\Intelligence\Application\ProcessTemplateSuggestionService;
use App\Intelligence\Application\TemplateSuggestionService;
use App\Intelligence\Domain\ProcessEventRecord;
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

    public function testIgnoresBeforeEvents(): void
    {
        $tester = new CommandTester($this->command());

        $tester->execute([
            'documentUuid' => 'uuid-1',
            'processKey' => 'eingangsrechnung',
            '--document-version' => '1',
        ]);

        $template = Yaml::parse($tester->getDisplay());

        self::assertSame(['eingang', 'pruefung'], array_column($template['steps'], 'key'));
        self::assertNotContains('vorstempel', array_column($template['steps'], 'key'));
    }

    public function testIncludeBeforeOptionUsesBeforeEvents(): void
    {
        $tester = new CommandTester($this->command());

        $tester->execute([
            'documentUuid' => 'uuid-1',
            'processKey' => 'eingangsrechnung',
            '--document-version' => '1',
            '--include-before' => true,
        ]);

        $template = Yaml::parse($tester->getDisplay());

        self::assertSame(['eingang', 'vorstempel', 'pruefung'], array_column($template['steps'], 'key'));
        self::assertSame(
            [
                ['from' => 'eingang', 'to' => 'vorstempel'],
                ['from' => 'vorstempel', 'to' => 'pruefung'],
            ],
            $template['transitions']
        );
    }

    public function testNormalizesStepKeysForDirectDeduplication(): void
    {
        $tester = new CommandTester($this->commandWithEvents([
            $this->event(1, 'evt-normalize-1', 'Eingang', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(2, 'evt-normalize-2', ' prüfen ', 1, '2026-05-29T10:00:00+00:00'),
            $this->event(3, 'evt-normalize-3', 'pruefen', 1, '2026-05-29T10:05:00+00:00'),
            $this->event(4, 'evt-normalize-4', 'Freigabe', 1, '2026-05-29T11:00:00+00:00'),
        ]));

        $tester->execute([
            'documentUuid' => 'uuid-1',
            'processKey' => 'eingangsrechnung',
            '--document-version' => '1',
        ]);

        $template = Yaml::parse($tester->getDisplay());

        self::assertSame(['Eingang', ' prüfen ', 'Freigabe'], array_column($template['steps'], 'key'));
        self::assertSame(
            [
                ['from' => 'Eingang', 'to' => ' prüfen '],
                ['from' => ' prüfen ', 'to' => 'Freigabe'],
            ],
            $template['transitions']
        );
    }

    public function testDefaultOrderUsesReceivedAtForEqualOccurredAtValues(): void
    {
        $tester = new CommandTester($this->commandWithEvents([
            $this->event(1, 'evt-b', 'B', 1, '2026-05-29T09:00:00+00:00', receivedAt: '2026-05-29T09:00:02+00:00'),
            $this->event(2, 'evt-a', 'A', 1, '2026-05-29T09:00:00+00:00', receivedAt: '2026-05-29T09:00:01+00:00'),
            $this->event(3, 'evt-c', 'C', 1, '2026-05-29T09:00:00+00:00', receivedAt: '2026-05-29T09:00:03+00:00'),
        ]));

        $exitCode = $tester->execute([
            'documentUuid' => 'uuid-1',
            'processKey' => 'eingangsrechnung',
            '--document-version' => '1',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $template = Yaml::parse($tester->getDisplay());

        self::assertSame(['A', 'B', 'C'], array_column($template['steps'], 'key'));
    }

    public function testReceivedAtOrderOptionSortsOnlyByReceivedAt(): void
    {
        $tester = new CommandTester($this->commandWithEvents([
            $this->event(1, 'evt-a', 'A', 1, '2026-05-29T09:00:00+00:00', receivedAt: '2026-05-29T09:00:03+00:00'),
            $this->event(2, 'evt-b', 'B', 1, '2026-05-29T10:00:00+00:00', receivedAt: '2026-05-29T09:00:01+00:00'),
            $this->event(3, 'evt-c', 'C', 1, '2026-05-29T11:00:00+00:00', receivedAt: '2026-05-29T09:00:02+00:00'),
        ]));

        $exitCode = $tester->execute([
            'documentUuid' => 'uuid-1',
            'processKey' => 'eingangsrechnung',
            '--document-version' => '1',
            '--order-by' => 'received-at',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $template = Yaml::parse($tester->getDisplay());

        self::assertSame(['B', 'C', 'A'], array_column($template['steps'], 'key'));
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

    public function testScopeOptionSuggestsJourneyYamlFromAllObservedProcesses(): void
    {
        $tester = new CommandTester($this->commandWithEvents([
            $this->event(1, 'evt-import', 'start', 1, '2026-05-29T09:00:00+00:00', 'generic_document_import'),
            $this->event(2, 'evt-pruefung', 'start', 1, '2026-05-29T10:00:00+00:00', 'aufmass_pruefung'),
            $this->event(3, 'evt-export', 'start', 1, '2026-05-29T11:00:00+00:00', 'export_nevaris'),
        ]));

        $exitCode = $tester->execute([
            'documentUuid' => 'uuid-1',
            'processKey' => 'aufmass_journey',
            '--scope' => 'journey',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $template = Yaml::parse($tester->getDisplay());

        self::assertSame('aufmass_journey', $template['key']);
        self::assertSame('journey', $template['scope']);
        self::assertSame(
            ['generic_document_import', 'aufmass_pruefung', 'export_nevaris'],
            array_column($template['steps'], 'key')
        );
        self::assertSame('process', $template['steps'][0]['type']);
        self::assertSame('generic_document_import', $template['steps'][0]['process_key']);
        self::assertTrue($template['steps'][0]['required']);
        self::assertSame(
            [
                ['from' => 'generic_document_import', 'to' => 'aufmass_pruefung'],
                ['from' => 'aufmass_pruefung', 'to' => 'export_nevaris'],
            ],
            $template['transitions']
        );
    }

    private function command(): IntelligenceTemplateSuggestFromDocumentCommand
    {
        return $this->commandWithEvents([
            $this->event(1, 'evt-2', 'pruefung', 1, '2026-05-29T10:00:00+00:00'),
            $this->event(2, 'evt-1', 'eingang', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(3, 'evt-1-duplicate-step', 'eingang', 1, '2026-05-29T09:05:00+00:00'),
            $this->event(7, 'evt-before-only', 'vorstempel', 1, '2026-05-29T09:30:00+00:00', 'eingangsrechnung', 'before'),
            $this->event(4, 'evt-3', 'eingang', 2, '2026-05-29T11:00:00+00:00'),
            $this->event(5, 'evt-4', 'freigabe', 2, '2026-05-29T12:00:00+00:00'),
            $this->event(6, 'evt-other-process', 'archiv', 2, '2026-05-29T13:00:00+00:00', 'anderer-prozess'),
        ]);
    }

    /**
     * @param array<int, ProcessEventRecord> $events
     */
    private function commandWithEvents(array $events): IntelligenceTemplateSuggestFromDocumentCommand
    {
        $timelineProvider = new InMemoryDocumentTimelineProvider([], $events);

        return new IntelligenceTemplateSuggestFromDocumentCommand(
            new TemplateSuggestionService(
                new ProcessTemplateSuggestionService($timelineProvider),
                new JourneyTemplateSuggestionService($timelineProvider)
            )
        );
    }

    private function event(
        int $id,
        string $externalEventKey,
        string $stepKey,
        int $documentVersion,
        string $occurredAt,
        string $processKey = 'eingangsrechnung',
        string $eventPhase = 'after',
        ?string $receivedAt = null
    ): ProcessEventRecord {
        $time = new DateTimeImmutable($occurredAt);
        $receivedTime = $receivedAt === null ? $time : new DateTimeImmutable($receivedAt);

        return new ProcessEventRecord(
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
            $receivedTime,
            '{}',
            '{}',
            $documentVersion,
            $eventPhase
        );
    }
}
