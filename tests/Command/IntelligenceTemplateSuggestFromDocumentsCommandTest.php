<?php

namespace App\Tests\Command;

use App\Command\IntelligenceTemplateSuggestFromDocumentsCommand;
use App\Intelligence\Application\ProcessTemplateMultiDocumentSuggestionService;
use App\Intelligence\Domain\ProcessEventRecord;
use App\Intelligence\Infrastructure\Process\InMemoryDocumentTimelineProvider;
use App\Intelligence\Infrastructure\Process\InMemoryProcessDocumentUuidProvider;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;

class IntelligenceTemplateSuggestFromDocumentsCommandTest extends TestCase
{
    public function testCombinesStepsFromMultipleDocuments(): void
    {
        $tester = new CommandTester($this->commandWithEvents([
            $this->event(1, 'doc-a-1', 'eingang', 'doc-a', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(2, 'doc-a-2', 'pruefung', 'doc-a', 1, '2026-05-29T10:00:00+00:00'),
            $this->event(3, 'doc-b-1', 'eingang', 'doc-b', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(4, 'doc-b-2', 'freigabe', 'doc-b', 1, '2026-05-29T10:00:00+00:00'),
        ]));

        $exitCode = $tester->execute([
            'processKey' => 'eingangsrechnung',
            'documentUuids' => ['doc-a', 'doc-b'],
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $template = Yaml::parse($tester->getDisplay());

        self::assertSame(2, $template['documents_used']);
        self::assertSame(['doc-a', 'doc-b'], $template['document_uuids']);
        self::assertSame(['eingang', 'pruefung', 'freigabe'], array_column($template['steps'], 'key'));
    }

    public function testAutoSelectsDocumentUuidsWhenNoExplicitUuidsAreGiven(): void
    {
        $tester = new CommandTester($this->commandWithEvents([
            $this->event(1, 'doc-a-1', 'eingang', 'doc-a', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(2, 'doc-a-2', 'pruefung', 'doc-a', 1, '2026-05-29T10:00:00+00:00'),
            $this->event(3, 'doc-b-1', 'eingang', 'doc-b', 1, '2026-05-29T11:00:00+00:00'),
            $this->event(4, 'doc-b-2', 'freigabe', 'doc-b', 1, '2026-05-29T12:00:00+00:00'),
            $this->event(5, 'other-1', 'ignorieren', 'other-doc', 1, '2026-05-29T13:00:00+00:00', 'anderer-prozess'),
            $this->event(6, 'null-doc-1', 'ignorieren', null, 1, '2026-05-29T14:00:00+00:00'),
        ]));

        $exitCode = $tester->execute([
            'processKey' => 'eingangsrechnung',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $template = Yaml::parse($tester->getDisplay());

        self::assertSame(2, $template['documents_used']);
        self::assertSame(['doc-b', 'doc-a'], $template['document_uuids']);
        self::assertSame(['eingang', 'freigabe', 'pruefung'], array_column($template['steps'], 'key'));
    }

    public function testAutoSelectedDocumentUuidsCanBeLimited(): void
    {
        $tester = new CommandTester($this->commandWithEvents([
            $this->event(1, 'doc-a-1', 'A', 'doc-a', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(2, 'doc-b-1', 'B', 'doc-b', 1, '2026-05-29T10:00:00+00:00'),
            $this->event(3, 'doc-c-1', 'C', 'doc-c', 1, '2026-05-29T11:00:00+00:00'),
        ]));

        $exitCode = $tester->execute([
            'processKey' => 'eingangsrechnung',
            '--limit' => '2',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $template = Yaml::parse($tester->getDisplay());

        self::assertSame(2, $template['documents_used']);
        self::assertSame(['doc-c', 'doc-b'], $template['document_uuids']);
    }

    public function testAutoSelectedDocumentUuidsCanBeFilteredBySince(): void
    {
        $tester = new CommandTester($this->commandWithEvents([
            $this->event(1, 'doc-old-1', 'A', 'doc-old', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(2, 'doc-new-1', 'B', 'doc-new', 1, '2026-05-29T11:00:00+00:00'),
        ]));

        $exitCode = $tester->execute([
            'processKey' => 'eingangsrechnung',
            '--since' => '2026-05-29T10:00:00+00:00',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $template = Yaml::parse($tester->getDisplay());

        self::assertSame(1, $template['documents_used']);
        self::assertSame(['doc-new'], $template['document_uuids']);
    }

    public function testCountsObservedTransitionsAndConfidence(): void
    {
        $tester = new CommandTester($this->commandWithEvents([
            $this->event(1, 'doc-a-1', 'eingang', 'doc-a', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(2, 'doc-a-2', 'pruefung', 'doc-a', 1, '2026-05-29T10:00:00+00:00'),
            $this->event(3, 'doc-b-1', 'eingang', 'doc-b', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(4, 'doc-b-2', 'pruefung', 'doc-b', 1, '2026-05-29T10:00:00+00:00'),
            $this->event(5, 'doc-c-1', 'eingang', 'doc-c', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(6, 'doc-c-2', 'freigabe', 'doc-c', 1, '2026-05-29T10:00:00+00:00'),
        ]));

        $tester->execute([
            'processKey' => 'eingangsrechnung',
            'documentUuids' => ['doc-a', 'doc-b', 'doc-c'],
        ]);

        $template = Yaml::parse($tester->getDisplay());

        self::assertSame(
            [
                ['from' => 'eingang', 'to' => 'pruefung', 'observed_count' => 2, 'confidence' => 1.0],
                ['from' => 'eingang', 'to' => 'freigabe', 'observed_count' => 1, 'confidence' => 0.5],
            ],
            $template['transitions']
        );
        self::assertSame(
            [
                'type' => 'possible_decision_point',
                'after_step' => 'eingang',
                'observed_next_steps' => ['pruefung', 'freigabe'],
                'message' => 'Multiple next steps observed after eingang. Context fields may be required to explain routing.',
            ],
            $template['suggestions'][0]
        );
    }

    public function testSuggestsPossibleDecisionPointForMultipleObservedNextSteps(): void
    {
        $tester = new CommandTester($this->commandWithEvents([
            $this->event(1, 'doc-a-1', '01 Rechnungen pruefen', 'doc-a', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(2, 'doc-a-2', '05 Ausgangsrechnung buchen', 'doc-a', 1, '2026-05-29T10:00:00+00:00'),
            $this->event(3, 'doc-b-1', '01 Rechnungen pruefen', 'doc-b', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(4, 'doc-b-2', '03 Freigabe_klein', 'doc-b', 1, '2026-05-29T10:00:00+00:00'),
            $this->event(5, 'doc-c-1', '01 Rechnungen pruefen', 'doc-c', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(6, 'doc-c-2', '02 Versenden', 'doc-c', 1, '2026-05-29T10:00:00+00:00'),
            $this->event(7, 'doc-d-1', '01 Rechnungen pruefen', 'doc-d', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(8, 'doc-d-2', '07 Zahlungseingang erwartet', 'doc-d', 1, '2026-05-29T10:00:00+00:00'),
        ]));

        $tester->execute([
            'processKey' => 'eingangsrechnung',
            'documentUuids' => ['doc-a', 'doc-b', 'doc-c', 'doc-d'],
        ]);

        $template = Yaml::parse($tester->getDisplay());

        self::assertSame(
            [
                'type' => 'possible_decision_point',
                'after_step' => '01 Rechnungen pruefen',
                'observed_next_steps' => [
                    '05 Ausgangsrechnung buchen',
                    '03 Freigabe_klein',
                    '02 Versenden',
                    '07 Zahlungseingang erwartet',
                ],
                'message' => 'Multiple next steps observed after 01 Rechnungen pruefen. Context fields may be required to explain routing.',
            ],
            $template['suggestions'][0]
        );
    }

    public function testDefaultOrderUsesReceivedAtForEqualOccurredAtValuesAcrossDocuments(): void
    {
        $tester = new CommandTester($this->commandWithEvents([
            $this->event(1, 'doc-a-b', 'B', 'doc-a', 1, '2026-05-29T09:00:00+00:00', receivedAt: '2026-05-29T09:00:02+00:00'),
            $this->event(2, 'doc-a-a', 'A', 'doc-a', 1, '2026-05-29T09:00:00+00:00', receivedAt: '2026-05-29T09:00:01+00:00'),
            $this->event(3, 'doc-b-c', 'C', 'doc-b', 1, '2026-05-29T09:00:00+00:00', receivedAt: '2026-05-29T09:00:01+00:00'),
            $this->event(4, 'doc-b-d', 'D', 'doc-b', 1, '2026-05-29T09:00:00+00:00', receivedAt: '2026-05-29T09:00:02+00:00'),
        ]));

        $exitCode = $tester->execute([
            'processKey' => 'eingangsrechnung',
            'documentUuids' => ['doc-a', 'doc-b'],
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $template = Yaml::parse($tester->getDisplay());

        self::assertSame(['A', 'B', 'C', 'D'], array_column($template['steps'], 'key'));
        self::assertSame(
            [
                ['from' => 'A', 'to' => 'B', 'observed_count' => 1, 'confidence' => 1.0],
                ['from' => 'C', 'to' => 'D', 'observed_count' => 1, 'confidence' => 1.0],
            ],
            $template['transitions']
        );
    }

    public function testMarksConflictingTransitions(): void
    {
        $tester = new CommandTester($this->commandWithEvents([
            $this->event(1, 'doc-a-1', '02 Versenden', 'doc-a', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(2, 'doc-a-2', '04 Zahlungseingang erwartet', 'doc-a', 1, '2026-05-29T10:00:00+00:00'),
            $this->event(3, 'doc-b-1', '04 Zahlungseingang erwartet', 'doc-b', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(4, 'doc-b-2', '02 Versenden', 'doc-b', 1, '2026-05-29T10:00:00+00:00'),
        ]));

        $tester->execute([
            'processKey' => 'eingangsrechnung',
            'documentUuids' => ['doc-a', 'doc-b'],
        ]);

        $template = Yaml::parse($tester->getDisplay());

        self::assertSame('conflicting_transition', $template['warnings'][0]['type']);
        self::assertSame(
            'Observed both 02 Versenden -> 04 Zahlungseingang erwartet and 04 Zahlungseingang erwartet -> 02 Versenden',
            $template['warnings'][0]['message']
        );
    }

    public function testSuggestsPossibleParallelGroupForObservedReversedStepOrder(): void
    {
        $tester = new CommandTester($this->commandWithEvents([
            $this->event(1, 'doc-a-1', 'A', 'doc-a', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(2, 'doc-a-2', 'B', 'doc-a', 1, '2026-05-29T10:00:00+00:00'),
            $this->event(3, 'doc-a-3', 'C', 'doc-a', 1, '2026-05-29T11:00:00+00:00'),
            $this->event(4, 'doc-b-1', 'A', 'doc-b', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(5, 'doc-b-2', 'C', 'doc-b', 1, '2026-05-29T10:00:00+00:00'),
            $this->event(6, 'doc-b-3', 'B', 'doc-b', 1, '2026-05-29T11:00:00+00:00'),
        ]));

        $tester->execute([
            'processKey' => 'eingangsrechnung',
            'documentUuids' => ['doc-a', 'doc-b'],
        ]);

        $template = Yaml::parse($tester->getDisplay());

        self::assertSame(
            [
                ['from' => 'A', 'to' => 'B', 'observed_count' => 1, 'confidence' => 1.0],
                ['from' => 'B', 'to' => 'C', 'observed_count' => 1, 'confidence' => 1.0],
                ['from' => 'A', 'to' => 'C', 'observed_count' => 1, 'confidence' => 1.0],
                ['from' => 'C', 'to' => 'B', 'observed_count' => 1, 'confidence' => 1.0],
            ],
            $template['transitions']
        );
        self::assertSame(
            [
                [
                    'key' => 'suggested_parallel_1',
                    'after' => 'A',
                    'required_steps' => ['B', 'C'],
                    'order' => 'any',
                    'confidence' => 0.5,
                    'reason' => 'Observed both orders across document timelines.',
                    'document_uuids' => ['doc-a', 'doc-b'],
                ],
            ],
            $template['parallel_groups']
        );
        self::assertContains('possible_parallel', array_column($template['warnings'], 'type'));
        $parallelWarningIndex = array_search('possible_parallel', array_column($template['warnings'], 'type'), true);
        self::assertSame(['doc-a', 'doc-b'], $template['warnings'][$parallelWarningIndex]['document_uuids']);
        self::assertSame('possible_parallel_group', $template['suggestions'][0]['type']);
        self::assertSame(['doc-a', 'doc-b'], $template['suggestions'][0]['document_uuids']);
    }

    public function testDoesNotSetParallelGroupAfterWhenCommonPredecessorIsAmbiguous(): void
    {
        $tester = new CommandTester($this->commandWithEvents([
            $this->event(1, 'doc-a-1', 'X', 'doc-a', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(2, 'doc-a-2', 'B', 'doc-a', 1, '2026-05-29T10:00:00+00:00'),
            $this->event(3, 'doc-a-3', 'C', 'doc-a', 1, '2026-05-29T11:00:00+00:00'),
            $this->event(4, 'doc-a-4', 'D', 'doc-a', 1, '2026-05-29T12:00:00+00:00'),
            $this->event(5, 'doc-b-1', 'Y', 'doc-b', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(6, 'doc-b-2', 'C', 'doc-b', 1, '2026-05-29T10:00:00+00:00'),
            $this->event(7, 'doc-b-3', 'B', 'doc-b', 1, '2026-05-29T11:00:00+00:00'),
            $this->event(8, 'doc-b-4', 'D', 'doc-b', 1, '2026-05-29T12:00:00+00:00'),
        ]));

        $tester->execute([
            'processKey' => 'eingangsrechnung',
            'documentUuids' => ['doc-a', 'doc-b'],
        ]);

        $template = Yaml::parse($tester->getDisplay());

        self::assertSame(
            [
                [
                    'key' => 'suggested_parallel_1',
                    'required_steps' => ['B', 'C'],
                    'order' => 'any',
                    'confidence' => 0.5,
                    'reason' => 'Observed both orders across document timelines.',
                    'document_uuids' => ['doc-a', 'doc-b'],
                ],
            ],
            $template['parallel_groups']
        );
    }

    public function testSuggestsPossibleParallelGroupForBidirectionalTransitionsWithVariableBoundaryContext(): void
    {
        $tester = new CommandTester($this->commandWithEvents([
            $this->event(1, 'doc-a-1', 'X', 'doc-a', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(2, 'doc-a-2', 'B', 'doc-a', 1, '2026-05-29T10:00:00+00:00'),
            $this->event(3, 'doc-a-3', 'C', 'doc-a', 1, '2026-05-29T11:00:00+00:00'),
            $this->event(4, 'doc-a-4', 'D', 'doc-a', 1, '2026-05-29T12:00:00+00:00'),
            $this->event(5, 'doc-b-1', 'Y', 'doc-b', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(6, 'doc-b-2', 'B', 'doc-b', 1, '2026-05-29T10:00:00+00:00'),
            $this->event(7, 'doc-b-3', 'C', 'doc-b', 1, '2026-05-29T11:00:00+00:00'),
            $this->event(8, 'doc-b-4', 'E', 'doc-b', 1, '2026-05-29T12:00:00+00:00'),
            $this->event(9, 'doc-c-1', 'M', 'doc-c', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(10, 'doc-c-2', 'C', 'doc-c', 1, '2026-05-29T10:00:00+00:00'),
            $this->event(11, 'doc-c-3', 'B', 'doc-c', 1, '2026-05-29T11:00:00+00:00'),
            $this->event(12, 'doc-c-4', 'F', 'doc-c', 1, '2026-05-29T12:00:00+00:00'),
            $this->event(13, 'doc-d-1', 'N', 'doc-d', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(14, 'doc-d-2', 'C', 'doc-d', 1, '2026-05-29T10:00:00+00:00'),
            $this->event(15, 'doc-d-3', 'B', 'doc-d', 1, '2026-05-29T11:00:00+00:00'),
            $this->event(16, 'doc-d-4', 'G', 'doc-d', 1, '2026-05-29T12:00:00+00:00'),
        ]));

        $tester->execute([
            'processKey' => 'eingangsrechnung',
            'documentUuids' => ['doc-a', 'doc-b', 'doc-c', 'doc-d'],
        ]);

        $template = Yaml::parse($tester->getDisplay());

        self::assertSame(
            [
                [
                    'key' => 'suggested_parallel_1',
                    'required_steps' => ['B', 'C'],
                    'order' => 'any',
                    'confidence' => 0.5,
                    'reason' => 'Observed bidirectional direct transitions; boundary context varies.',
                    'document_uuids' => ['doc-a', 'doc-b', 'doc-c', 'doc-d'],
                ],
            ],
            $template['parallel_groups']
        );
        self::assertSame('possible_parallel_group', $template['suggestions'][0]['type']);
        self::assertSame(
            'Observed bidirectional direct transitions; boundary context varies.',
            $template['suggestions'][0]['message']
        );
    }

    public function testSuggestsPossibleParallelGroupForRepeatedBidirectionalDirectTransitionsWithoutStableBoundary(): void
    {
        $tester = new CommandTester($this->commandWithEvents([
            $this->event(1, 'doc-a-1', '01 Rechnungen pruefen', 'doc-a', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(2, 'doc-a-2', '05 Ausgangsrechnung buchen', 'doc-a', 1, '2026-05-29T10:00:00+00:00'),
            $this->event(3, 'doc-a-3', '07 Zahlungseingang erwartet', 'doc-a', 1, '2026-05-29T11:00:00+00:00'),
            $this->event(4, 'doc-a-4', '05 Ausgangsrechnung buchen', 'doc-a', 1, '2026-05-29T12:00:00+00:00'),
            $this->event(5, 'doc-a-5', '09 Rechnungen Abschluss', 'doc-a', 1, '2026-05-29T13:00:00+00:00'),
            $this->event(6, 'doc-b-1', '02 Versenden', 'doc-b', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(7, 'doc-b-2', '05 Ausgangsrechnung buchen', 'doc-b', 1, '2026-05-29T10:00:00+00:00'),
            $this->event(8, 'doc-b-3', '07 Zahlungseingang erwartet', 'doc-b', 1, '2026-05-29T11:00:00+00:00'),
            $this->event(9, 'doc-b-4', '05 Ausgangsrechnung buchen', 'doc-b', 1, '2026-05-29T12:00:00+00:00'),
            $this->event(10, 'doc-b-5', '10 Mahnung vorbereiten', 'doc-b', 1, '2026-05-29T13:00:00+00:00'),
            $this->event(11, 'doc-c-1', '03 Freigabe_klein', 'doc-c', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(12, 'doc-c-2', '07 Zahlungseingang erwartet', 'doc-c', 1, '2026-05-29T10:00:00+00:00'),
            $this->event(13, 'doc-c-3', '05 Ausgangsrechnung buchen', 'doc-c', 1, '2026-05-29T11:00:00+00:00'),
            $this->event(14, 'doc-c-4', '07 Zahlungseingang erwartet', 'doc-c', 1, '2026-05-29T12:00:00+00:00'),
            $this->event(15, 'doc-c-5', '11 Zahlung klaeren', 'doc-c', 1, '2026-05-29T13:00:00+00:00'),
            $this->event(16, 'doc-d-1', '04 Freigabe_gross', 'doc-d', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(17, 'doc-d-2', '07 Zahlungseingang erwartet', 'doc-d', 1, '2026-05-29T10:00:00+00:00'),
            $this->event(18, 'doc-d-3', '05 Ausgangsrechnung buchen', 'doc-d', 1, '2026-05-29T11:00:00+00:00'),
            $this->event(19, 'doc-d-4', '07 Zahlungseingang erwartet', 'doc-d', 1, '2026-05-29T12:00:00+00:00'),
            $this->event(20, 'doc-d-5', '12 Archivieren', 'doc-d', 1, '2026-05-29T13:00:00+00:00'),
            $this->event(21, 'doc-e-1', '05 Ausgangsrechnung buchen', 'doc-e', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(22, 'doc-e-2', '07 Zahlungseingang erwartet', 'doc-e', 1, '2026-05-29T10:00:00+00:00'),
            $this->event(23, 'doc-e-3', '13 Nachlauf', 'doc-e', 1, '2026-05-29T11:00:00+00:00'),
        ]));

        $tester->execute([
            'processKey' => 'eingangsrechnung',
            'documentUuids' => ['doc-a', 'doc-b', 'doc-c', 'doc-d', 'doc-e'],
        ]);

        $template = Yaml::parse($tester->getDisplay());

        self::assertSame(
            [
                [
                    'key' => 'suggested_parallel_1',
                    'required_steps' => ['05 Ausgangsrechnung buchen', '07 Zahlungseingang erwartet'],
                    'order' => 'any',
                    'confidence' => 0.4444,
                    'reason' => 'Observed bidirectional direct transitions; boundary context varies.',
                    'document_uuids' => ['doc-a', 'doc-b', 'doc-c', 'doc-d', 'doc-e'],
                ],
            ],
            $template['parallel_groups']
        );
        self::assertContains('conflicting_transition', array_column($template['warnings'], 'type'));
        self::assertSame('possible_parallel_group', $template['suggestions'][0]['type']);
        self::assertSame(
            'Observed bidirectional direct transitions; boundary context varies.',
            $template['suggestions'][0]['message']
        );
    }

    public function testDoesNotUseBidirectionalFallbackForSingleObservedTransitions(): void
    {
        $tester = new CommandTester($this->commandWithEvents([
            $this->event(1, 'doc-a-1', 'X', 'doc-a', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(2, 'doc-a-2', 'B', 'doc-a', 1, '2026-05-29T10:00:00+00:00'),
            $this->event(3, 'doc-a-3', 'C', 'doc-a', 1, '2026-05-29T11:00:00+00:00'),
            $this->event(4, 'doc-a-4', 'D', 'doc-a', 1, '2026-05-29T12:00:00+00:00'),
            $this->event(5, 'doc-b-1', 'M', 'doc-b', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(6, 'doc-b-2', 'C', 'doc-b', 1, '2026-05-29T10:00:00+00:00'),
            $this->event(7, 'doc-b-3', 'B', 'doc-b', 1, '2026-05-29T11:00:00+00:00'),
            $this->event(8, 'doc-b-4', 'F', 'doc-b', 1, '2026-05-29T12:00:00+00:00'),
        ]));

        $tester->execute([
            'processKey' => 'eingangsrechnung',
            'documentUuids' => ['doc-a', 'doc-b'],
        ]);

        $template = Yaml::parse($tester->getDisplay());

        self::assertArrayNotHasKey('parallel_groups', $template);
        self::assertContains('possible_decision_point', array_column($template['suggestions'], 'type'));
        self::assertContains('conflicting_transition', array_column($template['warnings'], 'type'));
    }

    public function testBidirectionalFallbackKeepsStartStepProtection(): void
    {
        $tester = new CommandTester($this->commandWithEvents([
            $this->event(1, 'doc-a-1', 'A', 'doc-a', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(2, 'doc-a-2', 'B', 'doc-a', 1, '2026-05-29T10:00:00+00:00'),
            $this->event(3, 'doc-a-3', 'C', 'doc-a', 1, '2026-05-29T11:00:00+00:00'),
            $this->event(4, 'doc-b-1', 'A', 'doc-b', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(5, 'doc-b-2', 'B', 'doc-b', 1, '2026-05-29T10:00:00+00:00'),
            $this->event(6, 'doc-b-3', 'D', 'doc-b', 1, '2026-05-29T11:00:00+00:00'),
            $this->event(7, 'doc-c-1', 'B', 'doc-c', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(8, 'doc-c-2', 'A', 'doc-c', 1, '2026-05-29T10:00:00+00:00'),
            $this->event(9, 'doc-c-3', 'E', 'doc-c', 1, '2026-05-29T11:00:00+00:00'),
            $this->event(10, 'doc-d-1', 'B', 'doc-d', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(11, 'doc-d-2', 'A', 'doc-d', 1, '2026-05-29T10:00:00+00:00'),
            $this->event(12, 'doc-d-3', 'F', 'doc-d', 1, '2026-05-29T11:00:00+00:00'),
        ]));

        $tester->execute([
            'processKey' => 'eingangsrechnung',
            'documentUuids' => ['doc-a', 'doc-b', 'doc-c', 'doc-d'],
        ]);

        $template = Yaml::parse($tester->getDisplay());

        self::assertArrayNotHasKey('parallel_groups', $template);
        self::assertContains('possible_decision_point', array_column($template['suggestions'], 'type'));
        self::assertContains('conflicting_transition', array_column($template['warnings'], 'type'));
    }

    public function testLinearProcessDoesNotSuggestParallelGroup(): void
    {
        $tester = new CommandTester($this->commandWithEvents([
            $this->event(1, 'doc-a-1', 'A', 'doc-a', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(2, 'doc-a-2', 'B', 'doc-a', 1, '2026-05-29T10:00:00+00:00'),
            $this->event(3, 'doc-a-3', 'C', 'doc-a', 1, '2026-05-29T11:00:00+00:00'),
            $this->event(4, 'doc-b-1', 'A', 'doc-b', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(5, 'doc-b-2', 'B', 'doc-b', 1, '2026-05-29T10:00:00+00:00'),
            $this->event(6, 'doc-b-3', 'C', 'doc-b', 1, '2026-05-29T11:00:00+00:00'),
        ]));

        $tester->execute([
            'processKey' => 'eingangsrechnung',
            'documentUuids' => ['doc-a', 'doc-b'],
        ]);

        $template = Yaml::parse($tester->getDisplay());

        self::assertArrayNotHasKey('parallel_groups', $template);
        self::assertArrayNotHasKey('suggestions', $template);
        self::assertSame([], $template['warnings']);
    }

    public function testStartStepIsNeverSuggestedAsParallelGroup(): void
    {
        $tester = new CommandTester($this->commandWithEvents([
            $this->event(1, 'doc-a-1', 'A', 'doc-a', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(2, 'doc-a-2', 'B', 'doc-a', 1, '2026-05-29T10:00:00+00:00'),
            $this->event(3, 'doc-a-3', 'C', 'doc-a', 1, '2026-05-29T11:00:00+00:00'),
            $this->event(4, 'doc-b-1', 'B', 'doc-b', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(5, 'doc-b-2', 'A', 'doc-b', 1, '2026-05-29T10:00:00+00:00'),
            $this->event(6, 'doc-b-3', 'C', 'doc-b', 1, '2026-05-29T11:00:00+00:00'),
        ]));

        $tester->execute([
            'processKey' => 'eingangsrechnung',
            'documentUuids' => ['doc-a', 'doc-b'],
        ]);

        $template = Yaml::parse($tester->getDisplay());

        self::assertArrayNotHasKey('parallel_groups', $template);
        self::assertContains('possible_decision_point', array_column($template['suggestions'], 'type'));
    }

    public function testEndStepIsNeverSuggestedAsParallelGroup(): void
    {
        $tester = new CommandTester($this->commandWithEvents([
            $this->event(1, 'doc-a-1', 'A', 'doc-a', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(2, 'doc-a-2', 'B', 'doc-a', 1, '2026-05-29T10:00:00+00:00'),
            $this->event(3, 'doc-a-3', 'D', 'doc-a', 1, '2026-05-29T11:00:00+00:00'),
            $this->event(4, 'doc-b-1', 'A', 'doc-b', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(5, 'doc-b-2', 'B', 'doc-b', 1, '2026-05-29T10:00:00+00:00'),
            $this->event(6, 'doc-b-3', 'D', 'doc-b', 1, '2026-05-29T11:00:00+00:00'),
        ]));

        $tester->execute([
            'processKey' => 'eingangsrechnung',
            'documentUuids' => ['doc-a', 'doc-b'],
        ]);

        $template = Yaml::parse($tester->getDisplay());

        self::assertArrayNotHasKey('parallel_groups', $template);
        self::assertArrayNotHasKey('suggestions', $template);
    }

    public function testIgnoresBeforeEventsForParallelSuggestionByDefault(): void
    {
        $tester = new CommandTester($this->commandWithEvents([
            $this->event(1, 'doc-a-1', 'A', 'doc-a', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(2, 'doc-a-2', 'B', 'doc-a', 1, '2026-05-29T10:00:00+00:00'),
            $this->event(3, 'doc-a-3', 'C', 'doc-a', 1, '2026-05-29T11:00:00+00:00'),
            $this->event(4, 'doc-b-1', 'A', 'doc-b', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(5, 'doc-b-before', 'C', 'doc-b', 1, '2026-05-29T09:30:00+00:00', 'eingangsrechnung', 'before'),
            $this->event(6, 'doc-b-2', 'B', 'doc-b', 1, '2026-05-29T10:00:00+00:00'),
            $this->event(7, 'doc-b-3', 'C', 'doc-b', 1, '2026-05-29T11:00:00+00:00'),
        ]));

        $tester->execute([
            'processKey' => 'eingangsrechnung',
            'documentUuids' => ['doc-a', 'doc-b'],
        ]);

        $template = Yaml::parse($tester->getDisplay());

        self::assertArrayNotHasKey('parallel_groups', $template);
        self::assertSame([], $template['warnings']);
    }

    public function testIgnoresBeforeEventsByDefault(): void
    {
        $tester = new CommandTester($this->commandWithEvents([
            $this->event(1, 'doc-a-1', 'eingang', 'doc-a', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(2, 'doc-a-before', 'vorpruefung', 'doc-a', 1, '2026-05-29T09:30:00+00:00', 'eingangsrechnung', 'before'),
            $this->event(3, 'doc-a-2', 'freigabe', 'doc-a', 1, '2026-05-29T10:00:00+00:00'),
        ]));

        $tester->execute([
            'processKey' => 'eingangsrechnung',
            'documentUuids' => ['doc-a'],
        ]);

        $template = Yaml::parse($tester->getDisplay());

        self::assertSame(['eingang', 'freigabe'], array_column($template['steps'], 'key'));
        self::assertSame(
            [['from' => 'eingang', 'to' => 'freigabe', 'observed_count' => 1, 'confidence' => 1.0]],
            $template['transitions']
        );
    }

    public function testIncludeBeforeOptionIncludesBeforeEvents(): void
    {
        $tester = new CommandTester($this->commandWithEvents([
            $this->event(1, 'doc-a-1', 'eingang', 'doc-a', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(2, 'doc-a-before', 'vorpruefung', 'doc-a', 1, '2026-05-29T09:30:00+00:00', 'eingangsrechnung', 'before'),
            $this->event(3, 'doc-a-2', 'freigabe', 'doc-a', 1, '2026-05-29T10:00:00+00:00'),
        ]));

        $tester->execute([
            'processKey' => 'eingangsrechnung',
            'documentUuids' => ['doc-a'],
            '--include-before' => true,
        ]);

        $template = Yaml::parse($tester->getDisplay());

        self::assertSame(['eingang', 'vorpruefung', 'freigabe'], array_column($template['steps'], 'key'));
    }

    public function testUsesLatestDocumentVersionByDefault(): void
    {
        $tester = new CommandTester($this->commandWithEvents([
            $this->event(1, 'doc-a-old-1', 'eingang', 'doc-a', 1, '2026-05-29T09:00:00+00:00'),
            $this->event(2, 'doc-a-old-2', 'alte-pruefung', 'doc-a', 1, '2026-05-29T10:00:00+00:00'),
            $this->event(3, 'doc-a-new-1', 'eingang', 'doc-a', 2, '2026-05-29T11:00:00+00:00'),
            $this->event(4, 'doc-a-new-2', 'freigabe', 'doc-a', 2, '2026-05-29T12:00:00+00:00'),
        ]));

        $tester->execute([
            'processKey' => 'eingangsrechnung',
            'documentUuids' => ['doc-a'],
        ]);

        $template = Yaml::parse($tester->getDisplay());

        self::assertSame(['eingang', 'freigabe'], array_column($template['steps'], 'key'));
    }

    /**
     * @param array<int, ProcessEventRecord> $events
     */
    private function commandWithEvents(array $events): IntelligenceTemplateSuggestFromDocumentsCommand
    {
        return new IntelligenceTemplateSuggestFromDocumentsCommand(
            new ProcessTemplateMultiDocumentSuggestionService(
                new InMemoryDocumentTimelineProvider([], $events),
                new InMemoryProcessDocumentUuidProvider($events)
            )
        );
    }

    private function event(
        int $id,
        string $externalEventKey,
        string $stepKey,
        ?string $documentUuid,
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
            'external-' . $documentUuid,
            $documentUuid,
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
