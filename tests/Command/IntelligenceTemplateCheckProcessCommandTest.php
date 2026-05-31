<?php

namespace App\Tests\Command;

use App\Command\IntelligenceTemplateCheckProcessCommand;
use App\Intelligence\Application\DocumentTimelineProvider;
use App\Intelligence\Application\EventTimelineOrder;
use App\Intelligence\Application\ProcessDocumentRef;
use App\Intelligence\Application\ProcessDocumentUuidProvider;
use App\Intelligence\Application\ProcessTemplateCheckResult;
use App\Intelligence\Application\ProcessTemplateCheckService;
use App\Intelligence\Domain\ContextSnapshot;
use App\Intelligence\Domain\DocumentRef;
use App\Intelligence\Domain\ProcessEventRecord;
use App\Intelligence\Infrastructure\Process\InMemoryDocumentTimelineProvider;
use App\Intelligence\Infrastructure\Process\InMemoryProcessDocumentUuidProvider;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\BufferedOutput;
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
        self::assertStringContainsString('warning_count: 0', $tester->getDisplay());
        self::assertStringContainsString('deviation_count: 1', $tester->getDisplay());
        self::assertStringContainsString('error_count: 0', $tester->getDisplay());
        self::assertStringNotContainsString('documentUuid: doc-ok', $tester->getDisplay());
        self::assertStringContainsString('DEVIATION:', $tester->getDisplay());
        self::assertStringContainsString('documentId: external-doc-bad; documentUuid: doc-bad; status: DEVIATION; score:', $tester->getDisplay());
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
        self::assertSame(0, $report['warning_count']);
        self::assertSame(1, $report['deviation_count']);
        self::assertSame(0, $report['error_count']);
        self::assertSame([
            'OK' => 1,
            'WARNING' => 0,
            'DEVIATION' => 1,
            'UNCERTAIN_CONTEXT_STALE' => 0,
            'UNCERTAIN_CONTEXT_TIME_SKEW' => 0,
            'UNCHECKABLE_CONTEXT_MISSING' => 0,
            'ERROR' => 0,
        ], $report['status_counts']);
        self::assertSame([
            'UNCERTAIN_CONTEXT_STALE' => 0,
            'UNCERTAIN_CONTEXT_TIME_SKEW' => 0,
            'UNCHECKABLE_CONTEXT_MISSING' => 0,
        ], $report['context_status_counts']);
        self::assertCount(1, $report['documents']);
        self::assertSame('external-doc-bad', $report['documents'][0]['documentId']);
        self::assertSame('doc-bad', $report['documents'][0]['documentUuid']);
        self::assertSame('DEVIATION', $report['documents'][0]['status']);
        self::assertSame(4, $report['documents'][0]['problem_score']);
        self::assertSame([], $report['groups']['OK']);
        self::assertCount(1, $report['groups']['DEVIATION']);
        self::assertSame(['Missing step' => 1, 'Unexpected step' => 1], $report['deviation_summary']);
        self::assertSame([], $report['warning_summary']);
        self::assertSame('external-doc-bad', $report['top_problem_documents'][0]['documentId']);
        self::assertSame(4, $report['top_problem_documents'][0]['problem_score']);
    }

    public function testContextIssueCountersUseSameRowsAsContextIssueSummaryEvenWhenDocumentStatusIsDeviation(): void
    {
        $directory = sys_get_temp_dir().'/amagno-template-check-process-'.bin2hex(random_bytes(6));
        mkdir($directory, 0775, true);
        $path = $directory.'/eingangsrechnung.yaml';
        file_put_contents($path, <<<'YAML'
key: eingangsrechnung
version: draft
required_steps:
  - eingang
  - abschluss
steps:
  - key: eingang
  - key: freigabe
  - key: abschluss
context_profile:
  required: []
context_policy:
  snapshot:
    max_delay_seconds: 300
    stale_behavior: uncertain
field_mapping:
  amount:
    source: test
    stability: snapshot_required
decision_points:
  - key: route_after_eingang
    after: eingang
    required_fields:
      - amount
    rules:
      - when:
          amount:
            gt: 50
        expect_next: freigabe
YAML);
        $event = $this->event(1, 'doc-skew-deviation', 'eingang', 0);
        $snapshot = new ContextSnapshot(
            new DocumentRef('amagno', 'external-doc-skew-deviation', 'doc-skew-deviation', 1),
            new DateTimeImmutable('2026-05-29T10:00:00+00:00'),
            ['amount' => 100],
            [],
            'eingangsrechnung',
            'evt-1',
            1,
            new DateTimeImmutable('2026-05-29T10:05:00+00:00'),
            new DateTimeImmutable('2026-05-29T10:00:00+00:00')
        );
        $timelineProvider = new InMemoryDocumentTimelineProvider([], [$event], [$snapshot]);
        $tester = new CommandTester(new IntelligenceTemplateCheckProcessCommand(
            new ProcessTemplateCheckService($timelineProvider),
            new InMemoryProcessDocumentUuidProvider([$event])
        ));

        $exitCode = $tester->execute([
            'processKey' => 'eingangsrechnung',
            '--template' => $path,
            '--format' => 'json',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $report = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $report['deviation_count']);
        self::assertSame(1, $report['uncertain_context_time_skew_count']);
        self::assertSame(1, $report['context_status_counts']['UNCERTAIN_CONTEXT_TIME_SKEW']);
        self::assertSame(['Uncertain context time skew' => 1], $report['context_issue_summary']);
        self::assertSame('DEVIATION', $report['documents'][0]['status']);
        self::assertSame('UNCERTAIN_CONTEXT_TIME_SKEW', $report['documents'][0]['context_status']);
        self::assertStringContainsString('snapshot freshness_seconds=-300 is negative', $report['documents'][0]['context_issues'][0]);
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
        self::assertStringContainsString('documentUuid: doc-bad', $tester->getDisplay());
        self::assertStringNotContainsString('documentUuid: doc-ok', $tester->getDisplay());
    }

    public function testUnknownDocumentIdFallback(): void
    {
        $path = $this->templatePath(['eingang']);
        $events = [
            $this->event(1, 'doc-unknown', 'eingang', 0),
        ];
        $timelineProvider = new InMemoryDocumentTimelineProvider([], $events);
        $provider = new class implements ProcessDocumentUuidProvider {
            public function documentUuidsForProcess(string $processKey, ?DateTimeImmutable $since = null, ?int $limit = null): array
            {
                return ['doc-unknown'];
            }

            public function documentRefsForProcess(string $processKey, ?DateTimeImmutable $since = null, ?int $limit = null): array
            {
                return [new ProcessDocumentRef('doc-unknown')];
            }
        };
        $tester = new CommandTester(new IntelligenceTemplateCheckProcessCommand(
            new ProcessTemplateCheckService($timelineProvider),
            $provider
        ));

        $exitCode = $tester->execute([
            'processKey' => 'eingangsrechnung',
            '--template' => $path,
            '--show-ok' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('documentId: <unknown>; documentUuid: doc-unknown; status: OK; score: 0; warnings: 0; deviations: 0', $tester->getDisplay());
    }

    public function testShowOkListsOkDocuments(): void
    {
        $path = $this->templatePath(['eingang']);
        $tester = new CommandTester($this->command([
            $this->event(1, 'doc-ok', 'eingang', 0),
        ]));

        $exitCode = $tester->execute([
            'processKey' => 'eingangsrechnung',
            '--template' => $path,
            '--show-ok' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('OK:', $tester->getDisplay());
        self::assertStringContainsString('documentId: external-doc-ok; documentUuid: doc-ok; status: OK; score: 0; warnings: 0; deviations: 0', $tester->getDisplay());
    }

    public function testMissingContextIsWarning(): void
    {
        $path = $this->decisionTemplatePath();
        $tester = new CommandTester($this->command([
            $this->event(1, 'doc-warning', 'eingang', 0),
        ]));

        $exitCode = $tester->execute([
            'processKey' => 'eingangsrechnung',
            '--template' => $path,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('warning_count: 1', $tester->getDisplay());
        self::assertStringContainsString('deviation_count: 0', $tester->getDisplay());
        self::assertStringContainsString('WARNING:', $tester->getDisplay());
        self::assertStringContainsString('Warning Summary:', $tester->getDisplay());
        self::assertStringContainsString('Missing context route_after_eingang: 1', $tester->getDisplay());
        self::assertStringContainsString('documentId: external-doc-warning; documentUuid: doc-warning; status: WARNING; score: 0; warnings: 1; deviations: 0', $tester->getDisplay());
        self::assertStringContainsString('Missing context for decision point route_after_eingang: amount', $tester->getDisplay());
    }

    public function testTechnicalFailureIsError(): void
    {
        $path = $this->templatePath(['eingang']);
        $provider = new class implements ProcessDocumentUuidProvider {
            public function documentUuidsForProcess(string $processKey, ?DateTimeImmutable $since = null, ?int $limit = null): array
            {
                return ['doc-error'];
            }

            public function documentRefsForProcess(string $processKey, ?DateTimeImmutable $since = null, ?int $limit = null): array
            {
                return [new ProcessDocumentRef('doc-error', 'external-doc-error')];
            }
        };
        $timelineProvider = new class implements DocumentTimelineProvider {
            public function build(string $documentUuid, EventTimelineOrder $order = EventTimelineOrder::DEFAULT): \App\Intelligence\Application\DocumentTimelineReport
            {
                throw new \RuntimeException('Timeline backend unavailable');
            }
        };
        $tester = new CommandTester(new IntelligenceTemplateCheckProcessCommand(
            new ProcessTemplateCheckService($timelineProvider),
            $provider
        ));

        $exitCode = $tester->execute([
            'processKey' => 'eingangsrechnung',
            '--template' => $path,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('error_count: 1', $tester->getDisplay());
        self::assertStringContainsString('ERROR:', $tester->getDisplay());
        self::assertStringContainsString('Top Problem Documents:', $tester->getDisplay());
        self::assertStringContainsString('score: 5', $tester->getDisplay());
        self::assertStringContainsString('documentId: external-doc-error; documentUuid: doc-error; status: ERROR; score: 5; warnings: 0; deviations: 0', $tester->getDisplay());
        self::assertStringContainsString('Timeline backend unavailable', $tester->getDisplay());
    }

    public function testEmptyDeviationMessageUsesFallbackInTextAndJsonRows(): void
    {
        $command = $this->command([]);
        $rowMethod = new ReflectionMethod($command, 'row');
        $rowMethod->setAccessible(true);

        $row = $rowMethod->invoke(
            $command,
            new ProcessDocumentRef('doc-empty-message', '17555621'),
            new ProcessTemplateCheckResult([], [], [''])
        );

        self::assertSame('DEVIATION', $row['status']);
        self::assertSame(1, $row['problem_score']);
        self::assertSame(1, $row['deviation_count']);
        self::assertSame(['Unknown deviation'], $row['deviations']);
        self::assertStringContainsString('"Unknown deviation"', json_encode($row, JSON_THROW_ON_ERROR));

        $renderMethod = new ReflectionMethod($command, 'renderText');
        $renderMethod->setAccessible(true);
        $output = new BufferedOutput();
        $renderMethod->invoke($command, [
            'process_key' => 'eingangsrechnung',
            'template_key' => 'eingangsrechnung',
            'total_documents' => 1,
            'ok_count' => 0,
            'warning_count' => 0,
            'deviation_count' => 1,
            'error_count' => 0,
            'deviation_summary' => ['Unknown deviation' => 1],
            'warning_summary' => [],
            'top_problem_documents' => [$row],
            'groups' => [
                'OK' => [],
                'WARNING' => [],
                'DEVIATION' => [$row],
                'ERROR' => [],
            ],
        ], $output);

        $display = $output->fetch();
        self::assertStringContainsString('documentId: 17555621; documentUuid: doc-empty-message; status: DEVIATION; score: 1; warnings: 0; deviations: 1', $display);
        self::assertStringContainsString('Unknown deviation', $display);
    }

    public function testTopProblemDocumentsAreSortedByScoreViolationCountAndDocumentId(): void
    {
        $command = $this->command([]);
        $topProblemDocuments = new ReflectionMethod($command, 'topProblemDocuments');
        $topProblemDocuments->setAccessible(true);
        $problemScore = new ReflectionMethod($command, 'problemScore');
        $problemScore->setAccessible(true);

        $rows = [
            $this->problemRow('17555621', ['Missing step: pruefung'], []),
            $this->problemRow('41279772', ['Decision rule violation: route expected a but got b'], []),
            $this->problemRow('41279747', [
                'Missing step: pruefung',
                'Decision rule violation: route expected a but got b',
                'Parallel Group incomplete: booking (missing: payment)',
            ], []),
            $this->problemRow('10000000', [], ['Missing context for decision point route_after_pruefung: amount']),
        ];

        self::assertSame(0, $problemScore->invoke($command, [], ['Missing context for decision point route_after_pruefung: amount'], null));

        $top = $topProblemDocuments->invoke($command, $rows);
        self::assertSame('41279747', $top[0]['documentId']);
        self::assertSame(7, $top[0]['problem_score']);
        self::assertSame('17555621', $top[1]['documentId']);
        self::assertSame(3, $top[1]['problem_score']);
        self::assertSame('41279772', $top[2]['documentId']);
        self::assertSame(2, $top[2]['problem_score']);
        self::assertCount(3, $top);
    }

    public function testProblemSummariesAreSortedByFrequency(): void
    {
        $command = $this->command([]);
        $problemSummary = new ReflectionMethod($command, 'problemSummary');
        $problemSummary->setAccessible(true);

        $rows = [
            [
                'warnings' => [],
                'deviations' => [
                    'Decision rule violation: approval expected a but got b',
                    'Missing step: pruefung',
                ],
            ],
            [
                'warnings' => [],
                'deviations' => [
                    'Decision rule violation: route expected x but got y',
                    'Parallel Group incomplete: booking (missing: payment)',
                ],
            ],
            [
                'warnings' => [
                    'Missing context for decision point route_after_pruefung: amount',
                    'Missing context for decision point route_after_pruefung: amount',
                    'Missing context for decision point freigabe_ab_1000: amount',
                ],
                'deviations' => [
                    'Missing step: abschluss',
                ],
            ],
        ];

        self::assertSame(
            [
                'Decision rule violation' => 2,
                'Missing step' => 2,
                'Parallel Group incomplete' => 1,
            ],
            $problemSummary->invoke($command, $rows, 'deviations')
        );
        self::assertSame(
            [
                'Missing context route_after_pruefung' => 2,
                'Missing context freigabe_ab_1000' => 1,
            ],
            $problemSummary->invoke($command, $rows, 'warnings')
        );
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
     * @param array<int, string> $deviations
     * @param array<int, string> $warnings
     * @return array<string, mixed>
     */
    private function problemRow(string $documentId, array $deviations, array $warnings): array
    {
        $command = $this->command([]);
        $problemScore = new ReflectionMethod($command, 'problemScore');
        $problemScore->setAccessible(true);

        return [
            'documentId' => $documentId,
            'documentUuid' => 'uuid-'.$documentId,
            'documentVersion' => null,
            'status' => $deviations !== [] ? 'DEVIATION' : 'WARNING',
            'problem_score' => $problemScore->invoke($command, $deviations, $warnings, null),
            'warning_count' => count($warnings),
            'deviation_count' => count($deviations),
            'error' => null,
            'warnings' => $warnings,
            'deviations' => $deviations,
        ];
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

    private function decisionTemplatePath(): string
    {
        $directory = sys_get_temp_dir().'/amagno-template-check-process-'.bin2hex(random_bytes(6));
        mkdir($directory, 0775, true);
        $path = $directory.'/eingangsrechnung.yaml';

        file_put_contents($path, <<<'YAML'
key: eingangsrechnung
version: draft
steps:
  - key: eingang
  - key: freigabe
required_steps:
  - eingang
field_mapping:
  amount:
    source: test
    stability: immutable
decision_points:
  - key: route_after_eingang
    after: eingang
    required_fields:
      - amount
    rules:
      - when:
          amount:
            gt: 50
        expect_next: freigabe
context_profile:
  required: []
YAML);

        return $path;
    }
}
