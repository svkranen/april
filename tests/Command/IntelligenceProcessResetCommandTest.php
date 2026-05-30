<?php

namespace App\Tests\Command;

use App\Command\IntelligenceProcessResetCommand;
use App\Intelligence\Application\ProcessResetResult;
use App\Intelligence\Application\ProcessResetter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

class IntelligenceProcessResetCommandTest extends TestCase
{
    public function testDryRunDoesNotDeleteData(): void
    {
        $resetter = $this->resetter();
        $tester = new CommandTester($this->command($resetter));

        $exitCode = $tester->execute([
            'processKey' => 'invoice',
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(3, $resetter->totalRecords());
        self::assertStringContainsString('Would delete', $tester->getDisplay());
        self::assertStringContainsString('ProcessEvents: 1', $tester->getDisplay());
        self::assertStringContainsString('ProcessInstances: 1', $tester->getDisplay());
        self::assertStringContainsString('ContextSnapshots: 1', $tester->getDisplay());
    }

    public function testDoesNotDeleteWithoutYesWhenPromptIsDeclined(): void
    {
        $resetter = $this->resetter();
        $tester = new CommandTester($this->command($resetter));

        $exitCode = $tester->execute([
            'processKey' => 'invoice',
        ], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(3, $resetter->totalRecords());
        self::assertSame(0, $resetter->calls);
        self::assertStringContainsString('Reset cancelled', $tester->getDisplay());
    }

    public function testDeletesOnlyDataForProcessKeyWithYes(): void
    {
        $resetter = $this->resetter([
            ['type' => 'event', 'processKey' => 'invoice', 'documentUuid' => 'doc-1'],
            ['type' => 'instance', 'processKey' => 'invoice', 'documentUuid' => 'doc-1'],
            ['type' => 'snapshot', 'processKey' => 'invoice', 'documentUuid' => 'doc-1'],
            ['type' => 'event', 'processKey' => 'other', 'documentUuid' => 'doc-1'],
        ]);
        $tester = new CommandTester($this->command($resetter));

        $exitCode = $tester->execute([
            'processKey' => 'invoice',
            '--yes' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(
            [['type' => 'event', 'processKey' => 'other', 'documentUuid' => 'doc-1']],
            $resetter->records
        );
        self::assertStringContainsString('Deleted Intelligence data for process "invoice"', $tester->getDisplay());
        self::assertStringContainsString('ProcessEvents: 1', $tester->getDisplay());
    }

    public function testDocumentUuidOptionRestrictsDeletion(): void
    {
        $resetter = $this->resetter([
            ['type' => 'event', 'processKey' => 'invoice', 'documentUuid' => 'doc-1'],
            ['type' => 'instance', 'processKey' => 'invoice', 'documentUuid' => 'doc-1'],
            ['type' => 'snapshot', 'processKey' => 'invoice', 'documentUuid' => 'doc-2'],
            ['type' => 'event', 'processKey' => 'invoice', 'documentUuid' => 'doc-2'],
        ]);
        $tester = new CommandTester($this->command($resetter));

        $exitCode = $tester->execute([
            'processKey' => 'invoice',
            '--document-uuid' => 'doc-1',
            '--yes' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(
            [
                ['type' => 'snapshot', 'processKey' => 'invoice', 'documentUuid' => 'doc-2'],
                ['type' => 'event', 'processKey' => 'invoice', 'documentUuid' => 'doc-2'],
            ],
            $resetter->records
        );
        self::assertStringContainsString('document "doc-1"', $tester->getDisplay());
        self::assertStringContainsString('ProcessEvents: 1', $tester->getDisplay());
        self::assertStringContainsString('ProcessInstances: 1', $tester->getDisplay());
        self::assertStringContainsString('ContextSnapshots: 0', $tester->getDisplay());
    }

    public function testBlocksDeletionInProd(): void
    {
        $resetter = $this->resetter();
        $tester = new CommandTester($this->command($resetter, 'prod'));

        $exitCode = $tester->execute([
            'processKey' => 'invoice',
            '--yes' => true,
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame(0, $resetter->calls);
        self::assertStringContainsString('blocked in prod', $tester->getDisplay());
    }

    /**
     * @param array<int, array{type: string, processKey: string, documentUuid: string}>|null $records
     */
    private function resetter(?array $records = null): InMemoryProcessResetter
    {
        return new InMemoryProcessResetter($records ?? [
            ['type' => 'event', 'processKey' => 'invoice', 'documentUuid' => 'doc-1'],
            ['type' => 'instance', 'processKey' => 'invoice', 'documentUuid' => 'doc-1'],
            ['type' => 'snapshot', 'processKey' => 'invoice', 'documentUuid' => 'doc-1'],
        ]);
    }

    private function command(InMemoryProcessResetter $resetter, string $environment = 'dev'): IntelligenceProcessResetCommand
    {
        return new IntelligenceProcessResetCommand(
            $resetter,
            new ParameterBag(['kernel.environment' => $environment])
        );
    }
}

final class InMemoryProcessResetter implements ProcessResetter
{
    /** @var array<int, array{type: string, processKey: string, documentUuid: string}> */
    public array $records;
    public int $calls = 0;

    /**
     * @param array<int, array{type: string, processKey: string, documentUuid: string}> $records
     */
    public function __construct(array $records)
    {
        $this->records = $records;
    }

    public function reset(string $processKey, ?string $documentUuid = null, bool $dryRun = false): ProcessResetResult
    {
        ++$this->calls;
        $matches = array_filter(
            $this->records,
            static fn (array $record): bool => $record['processKey'] === $processKey
                && ($documentUuid === null || $record['documentUuid'] === $documentUuid)
        );

        if (!$dryRun) {
            $this->records = array_values(array_filter(
                $this->records,
                static fn (array $record): bool => $record['processKey'] !== $processKey
                    || ($documentUuid !== null && $record['documentUuid'] !== $documentUuid)
            ));
        }

        return new ProcessResetResult(
            $this->countType($matches, 'event'),
            $this->countType($matches, 'instance'),
            $this->countType($matches, 'snapshot'),
            $this->countType($matches, 'deviation'),
            $this->countType($matches, 'analysis'),
            $dryRun
        );
    }

    public function totalRecords(): int
    {
        return count($this->records);
    }

    /**
     * @param array<int, array{type: string, processKey: string, documentUuid: string}> $records
     */
    private function countType(array $records, string $type): int
    {
        return count(array_filter(
            $records,
            static fn (array $record): bool => $record['type'] === $type
        ));
    }
}
