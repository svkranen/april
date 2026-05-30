<?php

namespace App\Tests\Command;

use App\Command\IntelligenceProcessStatusCommand;
use App\Intelligence\Domain\ProcessEventRecord;
use App\Intelligence\Domain\ProcessInstance;
use App\Intelligence\Infrastructure\Process\InMemoryProcessStatusReportProvider;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class IntelligenceProcessStatusCommandTest extends TestCase
{
    public function testRendersTableStatusReport(): void
    {
        $tester = new CommandTester(new IntelligenceProcessStatusCommand($this->provider()));

        $exitCode = $tester->execute(['processKey' => 'invoice-process']);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Prozessinstanzen gesamt:', $display);
        self::assertStringContainsString('2', $display);
        self::assertStringContainsString('received', $display);
        self::assertStringContainsString('approved', $display);
        self::assertStringContainsString('uuid-1', $display);
        self::assertStringContainsString('evt-2', $display);
    }

    public function testRendersJsonStatusReport(): void
    {
        $tester = new CommandTester(new IntelligenceProcessStatusCommand($this->provider()));

        $exitCode = $tester->execute([
            'processKey' => 'invoice-process',
            '--format' => 'json',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('invoice-process', $data['processKey']);
        self::assertSame(2, $data['totalInstances']);
        self::assertSame(1, $data['countsByStep']['received']);
        self::assertSame(1, $data['countsByStep']['approved']);
        self::assertCount(2, $data['openInstances']);
        self::assertSame('evt-2', $data['latestEvents'][0]['externalEventKey']);
    }

    public function testRejectsInvalidFormat(): void
    {
        $tester = new CommandTester(new IntelligenceProcessStatusCommand($this->provider()));

        $exitCode = $tester->execute([
            'processKey' => 'invoice-process',
            '--format' => 'xml',
        ]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('Invalid --format', $tester->getDisplay());
    }

    private function provider(): InMemoryProcessStatusReportProvider
    {
        $firstAt = new DateTimeImmutable('2026-05-29T10:00:00+00:00');
        $secondAt = new DateTimeImmutable('2026-05-29T11:00:00+00:00');

        return new InMemoryProcessStatusReportProvider(
            [
                new ProcessInstance(
                    1,
                    'amagno',
                    'invoice-process',
                    'draft',
                    'doc-1',
                    'uuid-1',
                    1,
                    'running',
                    'received',
                    $firstAt,
                    $firstAt,
                    null,
                    $firstAt,
                    $firstAt,
                    ['evt-1']
                ),
                new ProcessInstance(
                    2,
                    'amagno',
                    'invoice-process',
                    'draft',
                    'doc-2',
                    'uuid-2',
                    1,
                    'running',
                    'approved',
                    $secondAt,
                    $secondAt,
                    null,
                    $secondAt,
                    $secondAt,
                    ['evt-2']
                ),
            ],
            [
                new ProcessEventRecord(
                    1,
                    'evt-1',
                    'amagno',
                    'invoice-process',
                    'received',
                    'received',
                    'doc-1',
                    'uuid-1',
                    1,
                    'user-1',
                    $firstAt,
                    $firstAt,
                    '{}',
                    '{}',
                    1
                ),
                new ProcessEventRecord(
                    2,
                    'evt-2',
                    'amagno',
                    'invoice-process',
                    'approved',
                    'approved',
                    'doc-2',
                    'uuid-2',
                    1,
                    'user-2',
                    $secondAt,
                    $secondAt,
                    '{}',
                    '{}',
                    2
                ),
            ]
        );
    }
}
