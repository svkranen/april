<?php

namespace App\Tests\Command;

use App\Command\IntelligenceTemplateCheckProcessCommand;
use App\Intelligence\Application\ProcessTemplateCheckService;
use App\Intelligence\Domain\ProcessEventRecord;
use App\Intelligence\Infrastructure\Process\InMemoryDocumentTimelineProvider;
use App\Intelligence\Infrastructure\Process\InMemoryProcessDocumentUuidProvider;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class IntelligenceTemplateCheckProcessCommandTest extends TestCase
{
    public function testChecksAllDocumentsAndPrintsSummary(): void
    {
        $path = $this->templatePath(['eingang', 'pruefung', 'freigabe']);
        $tester = new CommandTester($this->command([
            $this->event(1, 'doc-ok', 'eingang', 0),
            $this->event(2, 'doc-ok', 'pruefung', 1),
            $this->event(3, 'doc-ok', 'freigabe', 2),
            $this->event(4, 'doc-bad', 'eingang', 0),
            $this->event(5, 'doc-bad', 'freigabe', 1),
        ]));

        $exitCode = $tester->execute([
            'processKey' => 'eingangsrechnung',
            '--template' => $path,
            '--document-version' => '1',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('total_documents: 2', $tester->getDisplay());
        self::assertStringContainsString('ok_count: 1', $tester->getDisplay());
        self::assertStringContainsString('deviation_count: 1', $tester->getDisplay());
        self::assertStringContainsString('warning_count: 0', $tester->getDisplay());
        self::assertStringContainsString('documentUuid: doc-ok; status: OK; deviations: 0', $tester->getDisplay());
        self::assertStringContainsString('documentUuid: doc-bad; status: DEVIATION; deviations:', $tester->getDisplay());
        self::assertStringContainsString('Missing step: pruefung', $tester->getDisplay());
    }

    public function testJsonFormat(): void
    {
        $path = $this->templatePath(['eingang', 'pruefung']);
        $tester = new CommandTester($this->command([
            $this->event(1, 'doc-ok', 'eingang', 0),
            $this->event(2, 'doc-ok', 'pruefung', 1),
            $this->event(3, 'doc-bad', 'eingang', 0),
            $this->event(4, 'doc-bad', 'archiv', 1),
        ]));

        $exitCode = $tester->execute([
            'processKey' => 'eingangsrechnung',
            '--template' => $path,
            '--format' => 'json',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $report = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('eingangsrechnung', $report['process_key']);
        self::assertSame(2, $report['total_documents']);
        self::assertSame(1, $report['ok_count']);
        self::assertSame(1, $report['deviation_count']);
        self::assertSame(0, $report['warning_count']);
        self::assertSame('doc-ok', $report['documents'][0]['documentUuid']);
        self::assertSame('OK', $report['documents'][0]['status']);
        self::assertSame('doc-bad', $report['documents'][1]['documentUuid']);
        self::assertSame('DEVIATION', $report['documents'][1]['status']);
    }

    public function testOnlyDeviationsFiltersOkDocumentsFromDetails(): void
    {
        $path = $this->templatePath(['eingang', 'pruefung']);
        $tester = new CommandTester($this->command([
            $this->event(1, 'doc-ok', 'eingang', 0),
            $this->event(2, 'doc-ok', 'pruefung', 1),
            $this->event(3, 'doc-bad', 'eingang', 0),
        ]));

        $exitCode = $tester->execute([
            'processKey' => 'eingangsrechnung',
            '--template' => $path,
            '--only-deviations' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('total_documents: 2', $tester->getDisplay());
        self::assertStringContainsString('ok_count: 1', $tester->getDisplay());
        self::assertStringContainsString('deviation_count: 1', $tester->getDisplay());
        self::assertStringNotContainsString('documentUuid: doc-ok', $tester->getDisplay());
        self::assertStringContainsString('documentUuid: doc-bad', $tester->getDisplay());
    }

    /**
     * @param array<int, ProcessEventRecord> $events
     */
    private function command(array $events): IntelligenceTemplateCheckProcessCommand
    {
        $timelineProvider = new InMemoryDocumentTimelineProvider([], $events);

        return new IntelligenceTemplateCheckProcessCommand(
            new ProcessTemplateCheckService($timelineProvider),
            new InMemoryProcessDocumentUuidProvider($events)
        );
    }

    private function event(int $id, string $documentUuid, string $stepKey, int $minute): ProcessEventRecord
    {
        $time = new DateTimeImmutable(sprintf('2026-05-29T10:%02d:00+00:00', $minute));

        return new ProcessEventRecord(
            $id,
            sprintf('evt-%d', $id),
            'amagno',
            'eingangsrechnung',
            $stepKey,
            $stepKey,
            'external-'.$documentUuid,
            $documentUuid,
            1,
            'user-1',
            $time,
            $time,
            '{}',
            '{}',
            1
        );
    }

    /**
     * @param array<int, string> $stepKeys
     */
    private function templatePath(array $stepKeys): string
    {
        $directory = sys_get_temp_dir().'/amagno-template-check-process-'.bin2hex(random_bytes(6));
        mkdir($directory, 0775, true);
        $path = $directory.'/eingangsrechnung.yaml';

        $steps = array_map(
            static fn (string $stepKey): string => sprintf("  - key: '%s'", $stepKey),
            $stepKeys
        );

        file_put_contents($path, sprintf(
            "key: eingangsrechnung\nversion: draft\nsteps:\n%s\ntransitions: []\ncontext_profile:\n  required: []\n",
            implode("\n", $steps)
        ));

        return $path;
    }
}
