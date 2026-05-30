<?php

namespace App\Tests\Command;

use App\Command\IntelligenceEventsShowCommand;
use App\Intelligence\Domain\ContextSnapshot;
use App\Intelligence\Domain\DocumentRef;
use App\Intelligence\Domain\ProcessEventRecord;
use App\Intelligence\Infrastructure\Process\InMemoryEventDetailsProvider;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class IntelligenceEventsShowCommandTest extends TestCase
{
    public function testRendersBaseEventData(): void
    {
        $tester = new CommandTester(new IntelligenceEventsShowCommand($this->provider()));

        $exitCode = $tester->execute(['eventId' => '1']);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('externalEventKey', $display);
        self::assertStringContainsString('evt-1', $display);
        self::assertStringContainsString('documentExternalId', $display);
        self::assertStringContainsString('doc-1', $display);
        self::assertStringContainsString('documentUuid', $display);
        self::assertStringContainsString('uuid-1', $display);
        self::assertStringNotContainsString('Raw Payload', $display);
    }

    public function testRendersRawNormalizedAndContextJson(): void
    {
        $tester = new CommandTester(new IntelligenceEventsShowCommand($this->provider()));

        $exitCode = $tester->execute([
            'eventId' => '1',
            '--raw' => true,
            '--normalized' => true,
            '--context' => true,
        ]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Raw Payload', $display);
        self::assertStringContainsString('"documentId": "doc-1"', $display);
        self::assertStringContainsString('Normalized Event', $display);
        self::assertStringContainsString('"processKey": "eingangsrechnung"', $display);
        self::assertStringContainsString('Context Snapshot', $display);
        self::assertStringContainsString('"amount": 100', $display);
        self::assertStringContainsString('"missing cost_center"', $display);
    }

    public function testMissingEventReturnsFailure(): void
    {
        $tester = new CommandTester(new IntelligenceEventsShowCommand($this->provider()));

        $exitCode = $tester->execute(['eventId' => '99']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Event not found: 99', $tester->getDisplay());
    }

    private function provider(): InMemoryEventDetailsProvider
    {
        $time = new DateTimeImmutable('2026-05-29T10:00:00+00:00');

        return new InMemoryEventDetailsProvider(
            [
                new ProcessEventRecord(
                    1,
                    'evt-1',
                    'amagno',
                    'eingangsrechnung',
                    'received',
                    'eingang',
                    'doc-1',
                    'uuid-1',
                    2,
                    'user-1',
                    $time,
                    $time,
                    '{"documentId":"doc-1","nested":{"value":true}}',
                    '{"processKey":"eingangsrechnung","stepKey":"eingang"}',
                    11
                ),
            ],
            [
                new ContextSnapshot(
                    new DocumentRef('amagno', 'doc-1', 'uuid-1', 2),
                    $time,
                    ['amount' => 100],
                    ['missing cost_center'],
                    'eingangsrechnung',
                    'evt-1',
                    11
                ),
            ]
        );
    }
}
