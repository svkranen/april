<?php

namespace App\Tests\Command;

use App\Command\IntelligenceTemplateCheckJourneyDocumentsCommand;
use App\Intelligence\Application\JourneyDocumentCandidateProvider;
use App\Intelligence\Application\JourneyDocumentCheckService;
use App\Intelligence\Application\JourneyTemplateCheckService;
use App\Intelligence\Application\ProcessTemplateProvider;
use App\Intelligence\Domain\ProcessEventRecord;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateMatch;
use App\Intelligence\Domain\ProcessTemplateStep;
use App\Intelligence\Domain\ProcessTemplateTransition;
use App\Intelligence\Infrastructure\Process\InMemoryDocumentTimelineProvider;
use App\Intelligence\Infrastructure\Process\InMemoryProcessDocumentUuidProvider;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class IntelligenceTemplateCheckJourneyDocumentsCommandTest extends TestCase
{
    public function testChecksCandidateDocumentsForJourneyTemplate(): void
    {
        $events = [
            $this->event(1, 'uuid-1', 'RM_TEST_dokumenten_eingang', '2026-06-01T09:00:00+00:00'),
            $this->event(2, 'uuid-1', 'RM_TEST_aufmass', '2026-06-01T09:05:00+00:00'),
            $this->event(3, 'uuid-1', 'RM_TEST_NevarisExport', '2026-06-01T09:10:00+00:00'),
            $this->event(4, 'uuid-2', 'RM_TEST_dokumenten_eingang', '2026-06-01T10:00:00+00:00'),
        ];
        $tester = new CommandTester($this->command($events, ['rm_aufmass_journey' => $this->template()]));

        $exitCode = $tester->execute([
            'journeyKey' => 'rm_aufmass_journey',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('match_process_keys: RM_TEST_aufmass', $tester->getDisplay());
        self::assertStringContainsString('candidate_documents: 1', $tester->getDisplay());
        self::assertStringContainsString('document_uuid: uuid-1', $tester->getDisplay());
        self::assertStringContainsString('status: SATISFIED', $tester->getDisplay());
        self::assertStringNotContainsString('document_uuid: uuid-2', $tester->getDisplay());
    }

    public function testPrintsJourneyDeviationDetails(): void
    {
        $events = [
            $this->event(1, 'uuid-1', 'RM_TEST_aufmass', '2026-06-01T09:05:00+00:00'),
        ];
        $tester = new CommandTester($this->command($events, ['rm_aufmass_journey' => $this->template()]));

        $tester->execute([
            'journeyKey' => 'rm_aufmass_journey',
        ]);

        self::assertStringContainsString('status: DEVIATION', $tester->getDisplay());
        self::assertStringContainsString('step RM_aufmass_nev_export [RM_TEST_NevarisExport]', $tester->getDisplay());
        self::assertStringNotContainsString('step RM_aufmass_document_intake', $tester->getDisplay());
    }

    public function testTextOutputPrintsUnexpectedProcessDetails(): void
    {
        $events = [
            $this->event(1, 'uuid-1', 'RM_TEST_aufmass', '2026-06-01T09:05:00+00:00'),
            $this->event(2, 'uuid-1', 'RM_TEST_debitoren_gutschrift', '2026-06-01T09:07:00+00:00'),
            $this->event(3, 'uuid-1', 'RM_TEST_NevarisExport', '2026-06-01T09:10:00+00:00'),
        ];
        $tester = new CommandTester($this->command($events, ['rm_aufmass_journey' => $this->template()]));

        $tester->execute([
            'journeyKey' => 'rm_aufmass_journey',
        ]);

        self::assertStringContainsString('status: DEVIATION', $tester->getDisplay());
        self::assertStringContainsString('Unexpected processes:', $tester->getDisplay());
        self::assertStringContainsString('CRITICAL UNEXPECTED_PROCESS', $tester->getDisplay());
        self::assertStringContainsString('Kritische Abweichung: Unerwarteter Prozess außerhalb des Templates', $tester->getDisplay());
        self::assertStringContainsString('Process: RM_TEST_debitoren_gutschrift at 2026-06-01T09:07:00+00:00', $tester->getDisplay());
    }

    public function testJsonOutputContainsUnexpectedProcessCodeAndMessage(): void
    {
        $events = [
            $this->event(1, 'uuid-1', 'RM_TEST_aufmass', '2026-06-01T09:05:00+00:00'),
            $this->event(2, 'uuid-1', 'RM_TEST_debitoren_gutschrift', '2026-06-01T09:07:00+00:00'),
            $this->event(3, 'uuid-1', 'RM_TEST_NevarisExport', '2026-06-01T09:10:00+00:00'),
        ];
        $tester = new CommandTester($this->command($events, ['rm_aufmass_journey' => $this->template()]));

        $exitCode = $tester->execute([
            'journeyKey' => 'rm_aufmass_journey',
            '--format' => 'json',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $data = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('DEVIATION', $data['documents'][0]['status']);
        self::assertSame('UNEXPECTED_PROCESS', $data['documents'][0]['unexpected_processes'][0]['code']);
        self::assertSame('DEVIATION', $data['documents'][0]['unexpected_processes'][0]['status']);
        self::assertSame('CRITICAL', $data['documents'][0]['unexpected_processes'][0]['severity']);
        self::assertSame('RM_TEST_debitoren_gutschrift', $data['documents'][0]['unexpected_processes'][0]['processKey']);
        self::assertSame('Kritische Abweichung: Unerwarteter Prozess außerhalb des Templates', $data['documents'][0]['unexpected_processes'][0]['message']);
        self::assertSame(1, $data['documents'][0]['unexpected_processes'][0]['timelineIndex']);
        self::assertSame('2026-06-01T09:07:00+00:00', $data['documents'][0]['unexpected_processes'][0]['occurredAt']);
    }

    public function testMissingJourneyTemplateFailsClearly(): void
    {
        $tester = new CommandTester($this->command([], []));

        $exitCode = $tester->execute([
            'journeyKey' => 'missing_journey',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Journey template not found: missing_journey', $tester->getDisplay());
    }

    /**
     * @param array<int, ProcessEventRecord> $events
     * @param array<string, ProcessTemplate> $templates
     */
    private function command(array $events, array $templates): IntelligenceTemplateCheckJourneyDocumentsCommand
    {
        $timelineProvider = new InMemoryDocumentTimelineProvider([], $events);

        return new IntelligenceTemplateCheckJourneyDocumentsCommand(
            new JourneyDocumentCheckService(
                new JourneyDocumentCandidateProvider(new InMemoryProcessDocumentUuidProvider($events)),
                new JourneyTemplateCheckService($timelineProvider)
            ),
            new class($templates) implements ProcessTemplateProvider {
                /** @param array<string, ProcessTemplate> $templates */
                public function __construct(private readonly array $templates)
                {
                }

                public function findByProcessKey(string $processKey): ?ProcessTemplate
                {
                    return $this->templates[$processKey] ?? null;
                }
            }
        );
    }

    private function template(): ProcessTemplate
    {
        return new ProcessTemplate(
            'rm_aufmass_journey',
            scope: 'journey',
            match: new ProcessTemplateMatch(['RM_TEST_aufmass']),
            steps: [
                new ProcessTemplateStep('RM_aufmass_document_intake', type: 'process', processKey: 'RM_TEST_dokumenten_eingang', required: false),
                new ProcessTemplateStep('RM_aufmass_intake', type: 'process', processKey: 'RM_TEST_aufmass', required: true),
                new ProcessTemplateStep('RM_aufmass_nev_export', type: 'process', processKey: 'RM_TEST_NevarisExport', required: true),
            ],
            transitions: [
                new ProcessTemplateTransition('RM_aufmass_document_intake', 'RM_aufmass_intake'),
                new ProcessTemplateTransition('RM_aufmass_intake', 'RM_aufmass_nev_export'),
            ]
        );
    }

    private function event(int $id, string $documentUuid, string $processKey, string $occurredAt): ProcessEventRecord
    {
        $time = new DateTimeImmutable($occurredAt);

        return new ProcessEventRecord(
            $id,
            sprintf('evt-%d', $id),
            'test',
            $processKey,
            'start',
            'start',
            'doc-'.$documentUuid,
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
}
