<?php

namespace App\Tests\Command;

use App\Command\IntelligenceTemplateCheckJourneyCommand;
use App\Intelligence\Application\CrossProcessRoutingChecker;
use App\Intelligence\Domain\ContextSnapshot;
use App\Intelligence\Domain\DocumentRef;
use App\Intelligence\Domain\ProcessEventRecord;
use App\Intelligence\Infrastructure\Process\InMemoryContextSnapshotHistoryProvider;
use App\Intelligence\Infrastructure\Process\InMemoryDocumentTimelineProvider;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;

final class IntelligenceTemplateCheckJourneyCommandTest extends TestCase
{
    public function testChecksJourneyCrossProcessRouting(): void
    {
        $templatePath = $this->templatePath();
        $time = new DateTimeImmutable('2026-06-01T10:00:00+00:00');
        $events = [
            $this->event('source-route', 'debitoren_intake', '10 Intake abgeschlossen', 1, $time),
            $this->event('target-start', 'aufmass_workflow', 'aufmass_eingang', 1, new DateTimeImmutable('2026-06-01T10:05:00+00:00')),
        ];
        $snapshots = [
            new ContextSnapshot(
                new DocumentRef('amagno', 'doc-1', 'uuid-1', 1),
                $time,
                ['document_type' => 'aufmass'],
                [],
                'debitoren_intake',
                'source-route',
                null,
                $time
            ),
        ];
        $tester = new CommandTester(new IntelligenceTemplateCheckJourneyCommand(
            new CrossProcessRoutingChecker(
                new InMemoryDocumentTimelineProvider([], $events, $snapshots),
                new InMemoryContextSnapshotHistoryProvider($snapshots)
            )
        ));

        $exitCode = $tester->execute([
            'documentUuid' => 'uuid-1',
            'sourceProcessKey' => 'debitoren_intake',
            '--template' => $templatePath,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Status: SATISFIED', $tester->getDisplay());
        self::assertStringContainsString('Source process: debitoren_intake', $tester->getDisplay());
        self::assertStringContainsString('Rule: route_to_aufmass', $tester->getDisplay());
        self::assertStringContainsString('After step: 10 Intake abgeschlossen', $tester->getDisplay());
        self::assertStringContainsString('Expected process: aufmass_workflow', $tester->getDisplay());

        @unlink($templatePath);
    }

    private function templatePath(): string
    {
        $path = sys_get_temp_dir().'/april-cross-process-routing-'.bin2hex(random_bytes(4)).'.yaml';
        file_put_contents($path, Yaml::dump([
            'key' => 'debitoren_intake',
            'cross_process_routing' => [
                [
                    'key' => 'route_to_aufmass',
                    'after_step' => '10 Intake abgeschlossen',
                    'when' => [
                        'document_type' => 'aufmass',
                    ],
                    'expected_process' => 'aufmass_workflow',
                ],
            ],
        ], 4, 2));

        return $path;
    }

    private function event(string $externalEventKey, string $processKey, string $stepKey, int $documentVersion, DateTimeImmutable $time): ProcessEventRecord
    {
        return new ProcessEventRecord(
            null,
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
            null,
            'after'
        );
    }
}
