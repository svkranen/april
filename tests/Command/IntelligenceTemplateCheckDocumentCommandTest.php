<?php

namespace App\Tests\Command;

use App\Command\IntelligenceTemplateCheckDocumentCommand;
use App\Intelligence\Application\ProcessTemplateCheckService;
use App\Intelligence\Domain\ContextSnapshot;
use App\Intelligence\Domain\DocumentRef;
use App\Intelligence\Domain\ProcessEventRecord;
use App\Intelligence\Infrastructure\Process\InMemoryDocumentTimelineProvider;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class IntelligenceTemplateCheckDocumentCommandTest extends TestCase
{
    public function testHappyPathReturnsOk(): void
    {
        $path = $this->templatePath(['eingang', 'pruefung', 'freigabe']);
        $tester = new CommandTester($this->command([
            ['old-step', 1],
            ['eingang', 2],
            ['pruefung', 2],
            ['freigabe', 2],
        ]));

        $exitCode = $tester->execute([
            'documentUuid' => 'uuid-1',
            'processKey' => 'eingangsrechnung',
            '--template' => $path,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Status: OK', $tester->getDisplay());
        self::assertStringContainsString('Globale Pflichtschritte: eingang -> pruefung -> freigabe', $tester->getDisplay());
        self::assertStringContainsString('Ist-Schrittfolge: eingang -> pruefung -> freigabe', $tester->getDisplay());
        self::assertStringContainsString('- none', $tester->getDisplay());

        $this->removeTemplate($path);
    }

    public function testMissingStepReturnsDeviation(): void
    {
        $path = $this->templatePath(['eingang', 'pruefung', 'freigabe']);
        $tester = new CommandTester($this->command([
            ['eingang', 1],
            ['freigabe', 1],
        ]));

        $exitCode = $tester->execute([
            'documentUuid' => 'uuid-1',
            'processKey' => 'eingangsrechnung',
            '--template' => $path,
            '--document-version' => '1',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Status: DEVIATION', $tester->getDisplay());
        self::assertStringContainsString('Missing step: pruefung', $tester->getDisplay());

        $this->removeTemplate($path);
    }

    public function testUnexpectedStepReturnsDeviation(): void
    {
        $path = $this->templatePath(['eingang', 'pruefung']);
        $tester = new CommandTester($this->command([
            ['eingang', 1],
            ['pruefung', 1],
            ['archiv', 1],
        ]));

        $exitCode = $tester->execute([
            'documentUuid' => 'uuid-1',
            'processKey' => 'eingangsrechnung',
            '--template' => $path,
            '--document-version' => '1',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Status: DEVIATION', $tester->getDisplay());
        self::assertStringContainsString('Unexpected step: archiv', $tester->getDisplay());

        $this->removeTemplate($path);
    }

    public function testWrongOrderReturnsDeviation(): void
    {
        $path = $this->templatePath(['eingang', 'pruefung', 'freigabe']);
        $tester = new CommandTester($this->command([
            ['pruefung', 1],
            ['eingang', 1],
            ['freigabe', 1],
        ]));

        $exitCode = $tester->execute([
            'documentUuid' => 'uuid-1',
            'processKey' => 'eingangsrechnung',
            '--template' => $path,
            '--document-version' => '1',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Status: DEVIATION', $tester->getDisplay());
        self::assertStringContainsString('Wrong order', $tester->getDisplay());

        $this->removeTemplate($path);
    }

    public function testParallelGroupAllowsAnyOrder(): void
    {
        $path = $this->templatePath(
            ['01 Pruefen', '02 Versenden'],
            [
                [
                    'key' => 'buchung_und_zahlung',
                    'after' => '02 Versenden',
                    'required_steps' => ['03 Buchen', '04 Zahlungseingang erwartet'],
                    'order' => 'any',
                ],
            ]
        );
        $tester = new CommandTester($this->command([
            ['01 Pruefen', 1],
            ['02 Versenden', 1],
            ['03 Buchen', 1],
            ['04 Zahlungseingang erwartet', 1],
        ]));

        $exitCode = $tester->execute([
            'documentUuid' => 'uuid-1',
            'processKey' => 'eingangsrechnung',
            '--template' => $path,
            '--document-version' => '1',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Status: OK', $tester->getDisplay());
        self::assertStringContainsString('Globale Pflichtschritte: 01 Pruefen -> 02 Versenden', $tester->getDisplay());
        self::assertStringContainsString('Parallel Group satisfied: buchung_und_zahlung', $tester->getDisplay());
        self::assertStringContainsString('- none', $tester->getDisplay());

        $this->removeTemplate($path);
    }

    public function testParallelGroupAllowsAlternativeOrder(): void
    {
        $path = $this->templatePath(
            ['01 Pruefen', '02 Versenden'],
            [
                [
                    'key' => 'buchung_und_zahlung',
                    'after' => '02 Versenden',
                    'required_steps' => ['03 Buchen', '04 Zahlungseingang erwartet'],
                    'order' => 'any',
                ],
            ]
        );
        $tester = new CommandTester($this->command([
            ['01 Pruefen', 1],
            ['02 Versenden', 1],
            ['04 Zahlungseingang erwartet', 1],
            ['03 Buchen', 1],
        ]));

        $exitCode = $tester->execute([
            'documentUuid' => 'uuid-1',
            'processKey' => 'eingangsrechnung',
            '--template' => $path,
            '--document-version' => '1',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Status: OK', $tester->getDisplay());
        self::assertStringContainsString('Ist-Schrittfolge: 01 Pruefen -> 02 Versenden -> 04 Zahlungseingang erwartet -> 03 Buchen', $tester->getDisplay());
        self::assertStringContainsString('Parallel Group satisfied: buchung_und_zahlung', $tester->getDisplay());
        self::assertStringContainsString('- none', $tester->getDisplay());

        $this->removeTemplate($path);
    }

    public function testParallelGroupMissingSecondStepReturnsDeviation(): void
    {
        $path = $this->templatePath(
            ['01 Pruefen', '02 Versenden'],
            [
                [
                    'key' => 'buchung_und_zahlung',
                    'after' => '02 Versenden',
                    'required_steps' => ['03 Buchen', '04 Zahlungseingang erwartet'],
                    'order' => 'any',
                ],
            ]
        );
        $tester = new CommandTester($this->command([
            ['01 Pruefen', 1],
            ['02 Versenden', 1],
            ['03 Buchen', 1],
        ]));

        $exitCode = $tester->execute([
            'documentUuid' => 'uuid-1',
            'processKey' => 'eingangsrechnung',
            '--template' => $path,
            '--document-version' => '1',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Status: DEVIATION', $tester->getDisplay());
        self::assertStringContainsString('Parallel Group incomplete: buchung_und_zahlung (missing: 04 Zahlungseingang erwartet)', $tester->getDisplay());

        $this->removeTemplate($path);
    }

    public function testParallelGroupMissingFirstStepReturnsDeviation(): void
    {
        $path = $this->templatePath(
            ['01 Pruefen', '02 Versenden'],
            [
                [
                    'key' => 'buchung_und_zahlung',
                    'after' => '02 Versenden',
                    'required_steps' => ['03 Buchen', '04 Zahlungseingang erwartet'],
                    'order' => 'any',
                ],
            ]
        );
        $tester = new CommandTester($this->command([
            ['01 Pruefen', 1],
            ['02 Versenden', 1],
            ['04 Zahlungseingang erwartet', 1],
        ]));

        $exitCode = $tester->execute([
            'documentUuid' => 'uuid-1',
            'processKey' => 'eingangsrechnung',
            '--template' => $path,
            '--document-version' => '1',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Status: DEVIATION', $tester->getDisplay());
        self::assertStringContainsString('Parallel Group incomplete: buchung_und_zahlung (missing: 03 Buchen)', $tester->getDisplay());

        $this->removeTemplate($path);
    }

    public function testParallelGroupStillRequiresOrderOutsideGroup(): void
    {
        $path = $this->templatePath(
            ['02 Versenden', '03 Buchen', '04 Zahlungseingang erwartet'],
            [
                [
                    'key' => 'buchung_und_zahlung',
                    'after' => '02 Versenden',
                    'required_steps' => ['03 Buchen', '04 Zahlungseingang erwartet'],
                    'order' => 'any',
                ],
            ]
        );
        $tester = new CommandTester($this->command([
            ['03 Buchen', 1],
            ['02 Versenden', 1],
            ['04 Zahlungseingang erwartet', 1],
        ]));

        $exitCode = $tester->execute([
            'documentUuid' => 'uuid-1',
            'processKey' => 'eingangsrechnung',
            '--template' => $path,
            '--document-version' => '1',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Status: DEVIATION', $tester->getDisplay());
        self::assertStringContainsString('Wrong order', $tester->getDisplay());

        $this->removeTemplate($path);
    }

    public function testCheckDocumentReceivedAtOrderOptionSortsActualStepsByReceivedAt(): void
    {
        $path = $this->templatePath(['B', 'C', 'A']);
        $occurredA = new DateTimeImmutable('2026-05-29T09:00:00+00:00');
        $occurredB = new DateTimeImmutable('2026-05-29T10:00:00+00:00');
        $occurredC = new DateTimeImmutable('2026-05-29T11:00:00+00:00');
        $tester = new CommandTester($this->commandWithEvents([
            new ProcessEventRecord(1, 'evt-a', 'amagno', 'eingangsrechnung', 'A', 'A', 'doc-1', 'uuid-1', 1, 'user-1', $occurredA, new DateTimeImmutable('2026-05-29T09:00:03+00:00'), '{}', '{}', 1),
            new ProcessEventRecord(2, 'evt-b', 'amagno', 'eingangsrechnung', 'B', 'B', 'doc-1', 'uuid-1', 1, 'user-1', $occurredB, new DateTimeImmutable('2026-05-29T09:00:01+00:00'), '{}', '{}', 1),
            new ProcessEventRecord(3, 'evt-c', 'amagno', 'eingangsrechnung', 'C', 'C', 'doc-1', 'uuid-1', 1, 'user-1', $occurredC, new DateTimeImmutable('2026-05-29T09:00:02+00:00'), '{}', '{}', 1),
        ]));

        $exitCode = $tester->execute([
            'documentUuid' => 'uuid-1',
            'processKey' => 'eingangsrechnung',
            '--template' => $path,
            '--document-version' => '1',
            '--order-by' => 'received-at',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Status: OK', $tester->getDisplay());
        self::assertStringContainsString('Ist-Schrittfolge: B -> C -> A', $tester->getDisplay());

        $this->removeTemplate($path);
    }

    public function testSignChecksArePrintedWithoutPersonDetails(): void
    {
        $path = $this->templatePathWithSignCheck();
        $event = $this->event(1, 'approval', 1, 0);
        $tester = new CommandTester(new IntelligenceTemplateCheckDocumentCommand(
            new ProcessTemplateCheckService(
                new InMemoryDocumentTimelineProvider(
                    [],
                    [$event],
                    [$this->snapshot('evt-1', ['ToBeSignedBy' => ['Alice', 'Bob', 'Chris'], 'SignedBy' => ['Alice', 'Chris']])]
                )
            )
        ));

        $exitCode = $tester->execute([
            'documentUuid' => 'uuid-1',
            'processKey' => 'eingangsrechnung',
            '--template' => $path,
            '--document-version' => '1',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('SignChecks:', $tester->getDisplay());
        self::assertStringContainsString('bauleiter_freigabe: PARTIAL', $tester->getDisplay());
        self::assertStringContainsString('Erwartet: 3', $tester->getDisplay());
        self::assertStringContainsString('Vorhanden: 2', $tester->getDisplay());
        self::assertStringContainsString('Fehlend: 1', $tester->getDisplay());
        self::assertStringNotContainsString('Alice', $tester->getDisplay());
        self::assertStringNotContainsString('Bob', $tester->getDisplay());
        self::assertStringNotContainsString('Chris', $tester->getDisplay());

        $this->removeTemplate($path);
    }


    /**
     * @param array<int, array{0: string, 1: int}> $steps
     */
    private function command(array $steps): IntelligenceTemplateCheckDocumentCommand
    {
        return $this->commandWithEvents(array_map(
            fn (array $step, int $index): ProcessEventRecord => $this->event($index + 1, $step[0], $step[1], $index),
            $steps,
            array_keys($steps)
        ));
    }

    /**
     * @param array<int, ProcessEventRecord> $events
     */
    private function commandWithEvents(array $events): IntelligenceTemplateCheckDocumentCommand
    {
        return new IntelligenceTemplateCheckDocumentCommand(
            new ProcessTemplateCheckService(
                new InMemoryDocumentTimelineProvider(
                    [],
                    $events
                )
            )
        );
    }

    private function event(int $id, string $stepKey, int $documentVersion, int $minuteOffset): ProcessEventRecord
    {
        $time = (new DateTimeImmutable('2026-05-29T09:00:00+00:00'))->modify(sprintf('+%d minutes', $minuteOffset));

        return new ProcessEventRecord(
            $id,
            sprintf('evt-%d', $id),
            'amagno',
            'eingangsrechnung',
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

    /**
     * @param array<int, string> $stepKeys
     */
    private function templatePath(array $stepKeys, array $parallelGroups = []): string
    {
        $directory = sys_get_temp_dir() . '/amagno-template-check-' . bin2hex(random_bytes(6));
        mkdir($directory, 0775, true);
        $path = $directory . '/eingangsrechnung.yaml';

        $steps = array_map(
            static fn (string $stepKey): string => sprintf("  - key: %s\n    name: %s", $stepKey, ucfirst($stepKey)),
            $stepKeys
        );

        $parallelGroupsYaml = '';
        if ($parallelGroups !== []) {
            $parallelGroupsYaml = "parallel_groups:\n";
            foreach ($parallelGroups as $group) {
                $parallelGroupsYaml .= sprintf("  - key: %s\n    required_steps:\n", $group['key']);
                foreach ($group['required_steps'] as $requiredStep) {
                    $parallelGroupsYaml .= sprintf("      - '%s'\n", $requiredStep);
                }
                if (isset($group['after'])) {
                    $parallelGroupsYaml .= sprintf("    after: '%s'\n", $group['after']);
                }
                $parallelGroupsYaml .= sprintf("    order: %s\n", $group['order']);
            }
        }

        file_put_contents($path, sprintf(
            "key: eingangsrechnung\nname: Eingangsrechnung\nversion: draft\nsteps:\n%s\n%stransitions: []\ncontext_profile:\n  required: []\n",
            implode("\n", $steps),
            $parallelGroupsYaml
        ));

        return $path;
    }

    private function templatePathWithSignCheck(): string
    {
        $directory = sys_get_temp_dir() . '/amagno-template-check-' . bin2hex(random_bytes(6));
        mkdir($directory, 0775, true);
        $path = $directory . '/eingangsrechnung.yaml';

        file_put_contents($path, <<<YAML
key: eingangsrechnung
name: Eingangsrechnung
version: draft
steps:
  - key: approval
transitions: []
context_profile:
  required:
    - ToBeSignedBy
    - SignedBy
sign_checks:
  - key: bauleiter_freigabe
    label: "Freigabe durch alle vorgesehenen Bauleiter"
    required_set: ToBeSignedBy
    actual_set: SignedBy
    operator: required_subset_of_actual
YAML);

        return $path;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function snapshot(string $externalEventKey, array $attributes): ContextSnapshot
    {
        return new ContextSnapshot(
            new DocumentRef('amagno', 'doc-1', 'uuid-1', 1),
            new DateTimeImmutable('2026-05-29T09:00:00+00:00'),
            $attributes,
            [],
            'eingangsrechnung',
            $externalEventKey,
            1
        );
    }

    private function removeTemplate(string $path): void
    {
        unlink($path);
        rmdir(dirname($path));
    }
}
