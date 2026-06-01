<?php

namespace App\Tests\Command;

use App\Command\IntelligenceDocumentContextHistoryCommand;
use App\Intelligence\Application\ContextHistoryBuilder;
use App\Intelligence\Domain\ContextSnapshot;
use App\Intelligence\Domain\DocumentRef;
use App\Intelligence\Domain\ProcessEventRecord;
use App\Intelligence\Infrastructure\Process\InMemoryContextSnapshotHistoryProvider;
use App\Intelligence\Infrastructure\Process\InMemoryDocumentTimelineProvider;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class IntelligenceDocumentContextHistoryCommandTest extends TestCase
{
    public function testRendersReadableContextHistoryWithDiff(): void
    {
        $tester = new CommandTester($this->command());

        $exitCode = $tester->execute([
            'documentUuid' => 'uuid-1',
            'processKey' => 'invoice',
            '--diff' => true,
        ]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Context History for document uuid-1 / invoice', $display);
        self::assertStringContainsString('externalEventKey=export-ready', $display);
        self::assertStringContainsString('export_status: ready', $display);
        self::assertStringContainsString('export_status: ready -> picked_up -> written', $display);
        self::assertStringContainsString('row_number: 42', $display);
        self::assertStringContainsString('amount_net: 400', $display);
    }

    public function testRendersJsonContextHistoryWithDiff(): void
    {
        $tester = new CommandTester($this->command());

        $exitCode = $tester->execute([
            'documentUuid' => 'uuid-1',
            'processKey' => 'invoice',
            '--json' => true,
            '--diff' => true,
        ]);
        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame('uuid-1', $data['documentUuid']);
        self::assertCount(3, $data['entries']);
        self::assertSame('ready', $data['entries'][0]['contextJson']['export_status']);
        self::assertSame('written', $data['diff']['changed_fields']['export_status'][1]['to']);
    }

    private function command(): IntelligenceDocumentContextHistoryCommand
    {
        $snapshots = [
            $this->snapshot('export-ready', ['amount_net' => 400.0, 'cost_center' => '4711', 'export_status' => 'ready'], '2026-06-01T09:00:00+00:00'),
            $this->snapshot('export-picked-up', ['amount_net' => 400.0, 'cost_center' => '4711', 'export_status' => 'picked_up', 'batch_id' => '2026-06-01-001'], '2026-06-01T09:05:00+00:00'),
            $this->snapshot('nevaris-import-row-created', ['amount_net' => 400.0, 'cost_center' => '4711', 'export_status' => 'written', 'batch_id' => '2026-06-01-001', 'row_number' => 42], '2026-06-01T09:06:00+00:00'),
        ];
        $events = [
            $this->event('export-ready', 'export_ready', '2026-06-01T09:00:00+00:00'),
            $this->event('export-picked-up', 'export_picked_up', '2026-06-01T09:05:00+00:00'),
            $this->event('nevaris-import-row-created', 'row_created', '2026-06-01T09:06:00+00:00'),
        ];

        return new IntelligenceDocumentContextHistoryCommand(
            new ContextHistoryBuilder(
                new InMemoryContextSnapshotHistoryProvider($snapshots),
                new InMemoryDocumentTimelineProvider([], $events, $snapshots)
            )
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function snapshot(string $externalEventKey, array $attributes, string $occurredAt): ContextSnapshot
    {
        $time = new DateTimeImmutable($occurredAt);

        return new ContextSnapshot(
            new DocumentRef('test', 'doc-1', 'uuid-1', 12),
            $time,
            $attributes,
            [],
            'invoice',
            $externalEventKey,
            99,
            $time,
            $time
        );
    }

    private function event(string $externalEventKey, string $stepKey, string $occurredAt): ProcessEventRecord
    {
        $time = new DateTimeImmutable($occurredAt);

        return new ProcessEventRecord(null, $externalEventKey, 'test', 'invoice', $stepKey, $stepKey, 'doc-1', 'uuid-1', 12, null, $time, $time, '{}', '{}', 99);
    }
}
