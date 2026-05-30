<?php

namespace App\Tests\Command;

use App\Command\IntelligenceContextCoverageCommand;
use App\Intelligence\Domain\ContextSnapshot;
use App\Intelligence\Domain\DocumentRef;
use App\Intelligence\Infrastructure\Process\InMemoryContextCoverageReportProvider;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class IntelligenceContextCoverageCommandTest extends TestCase
{
    public function testRendersJsonCoverageReport(): void
    {
        $tester = new CommandTester(new IntelligenceContextCoverageCommand($this->provider()));

        $exitCode = $tester->execute([
            'processKey' => 'invoice',
            '--format' => 'json',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('invoice', $data['processKey']);
        self::assertSame(2, $data['snapshotCount']);
        self::assertSame('amount', $data['fields'][0]['fieldKey']);
        self::assertSame(0.5, $data['fields'][0]['coverage']);
        self::assertSame(['int'], $data['fields'][0]['observedTypes']);
        self::assertSame([12000], $data['fields'][0]['exampleValues']);
    }

    public function testRendersTableCoverageReport(): void
    {
        $tester = new CommandTester(new IntelligenceContextCoverageCommand($this->provider()));

        $exitCode = $tester->execute(['processKey' => 'invoice']);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Context Snapshots:', $display);
        self::assertStringContainsString('fieldKey', $display);
        self::assertStringContainsString('amount', $display);
        self::assertStringContainsString('50.00%', $display);
        self::assertStringContainsString('documentType', $display);
    }

    public function testRejectsInvalidFormat(): void
    {
        $tester = new CommandTester(new IntelligenceContextCoverageCommand($this->provider()));

        $exitCode = $tester->execute([
            'processKey' => 'invoice',
            '--format' => 'xml',
        ]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('Invalid --format', $tester->getDisplay());
    }

    private function provider(): InMemoryContextCoverageReportProvider
    {
        return new InMemoryContextCoverageReportProvider([
            $this->snapshot('invoice', ['amount' => 12000, 'documentType' => 'invoice']),
            $this->snapshot('invoice', ['documentType' => 'credit_note']),
            $this->snapshot('other', ['amount' => 999]),
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function snapshot(string $processKey, array $attributes): ContextSnapshot
    {
        return new ContextSnapshot(
            new DocumentRef('amagno', 'doc-1', 'uuid-1', 1),
            new DateTimeImmutable('2026-05-29T10:00:00+00:00'),
            $attributes,
            [],
            $processKey,
            'evt-1',
            1
        );
    }
}
