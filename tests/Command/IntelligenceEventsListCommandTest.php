<?php

namespace App\Tests\Command;

use App\Command\IntelligenceEventsListCommand;
use App\Intelligence\Domain\ProcessEventRecord;
use App\Intelligence\Infrastructure\Process\InMemoryEventListProvider;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class IntelligenceEventsListCommandTest extends TestCase
{
    public function testRendersEventTable(): void
    {
        $tester = new CommandTester(new IntelligenceEventsListCommand($this->provider()));

        $exitCode = $tester->execute([]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('externalEventKey', $display);
        self::assertStringContainsString('evt-3', $display);
        self::assertStringContainsString('eingangsrechnung', $display);
        self::assertStringContainsString('doc-2', $display);
        self::assertStringContainsString('uuid-2', $display);
        self::assertStringContainsString('processInstanceId', $display);
        self::assertStringContainsString('no', $display);
    }

    public function testFiltersByProcessKeyDocumentUuidDocumentIdAndSince(): void
    {
        $tester = new CommandTester(new IntelligenceEventsListCommand($this->provider()));

        $exitCode = $tester->execute([
            '--format' => 'json',
            '--process-key' => 'eingangsrechnung',
            '--document-uuid' => 'uuid-1',
            '--document-id' => 'doc-1',
            '--since' => '2026-05-29T10:30:00+00:00',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(1, $data);
        self::assertSame('evt-2', $data[0]['externalEventKey']);
        self::assertSame('doc-1', $data[0]['documentExternalId']);
        self::assertSame('uuid-1', $data[0]['documentUuid']);
    }

    public function testLimitRestrictsRows(): void
    {
        $tester = new CommandTester(new IntelligenceEventsListCommand($this->provider()));

        $tester->execute([
            '--format' => 'json',
            '--limit' => '2',
        ]);

        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(2, $data);
        self::assertSame(['evt-3', 'evt-2'], array_column($data, 'externalEventKey'));
    }

    public function testEmptyResultShowsMessage(): void
    {
        $tester = new CommandTester(new IntelligenceEventsListCommand($this->provider()));

        $exitCode = $tester->execute([
            '--document-uuid' => 'missing-uuid',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('No events found.', $tester->getDisplay());
    }

    public function testRejectsInvalidFormat(): void
    {
        $tester = new CommandTester(new IntelligenceEventsListCommand($this->provider()));

        $exitCode = $tester->execute(['--format' => 'xml']);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('Invalid --format', $tester->getDisplay());
    }

    private function provider(): InMemoryEventListProvider
    {
        return new InMemoryEventListProvider([
            $this->event(1, 'evt-1', 'eingangsrechnung', 'doc-1', 'uuid-1', 'eingang', '2026-05-29T10:00:00+00:00', 11),
            $this->event(2, 'evt-2', 'eingangsrechnung', 'doc-1', 'uuid-1', 'pruefung', '2026-05-29T11:00:00+00:00', 11),
            $this->event(3, 'evt-3', 'anderer-prozess', 'doc-2', 'uuid-2', 'archiv', '2026-05-29T12:00:00+00:00', 12),
        ]);
    }

    private function event(
        int $id,
        string $externalEventKey,
        string $processKey,
        string $documentExternalId,
        string $documentUuid,
        string $stepKey,
        string $time,
        int $processInstanceId
    ): ProcessEventRecord {
        $dateTime = new DateTimeImmutable($time);

        return new ProcessEventRecord(
            $id,
            $externalEventKey,
            'amagno',
            $processKey,
            $stepKey,
            $stepKey,
            $documentExternalId,
            $documentUuid,
            1,
            'user-1',
            $dateTime,
            $dateTime,
            '{}',
            '{}',
            $processInstanceId
        );
    }
}
