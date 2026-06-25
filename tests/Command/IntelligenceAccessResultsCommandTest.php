<?php

namespace App\Tests\Command;

use App\Command\IntelligenceAccessResultsCommand;
use App\Intelligence\Application\VisibilityCheckEvaluationResult;
use App\Intelligence\Application\VisibilityCheckResultSaveContext;
use App\Intelligence\Infrastructure\Access\InMemoryVisibilityCheckResultStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class IntelligenceAccessResultsCommandTest extends TestCase
{
    public function testListsStoredResultsAsTable(): void
    {
        $store = $this->seededStore();
        $tester = new CommandTester(new IntelligenceAccessResultsCommand($store));

        $exitCode = $tester->execute([
            'documentUuid' => 'doc-1',
            'processKey' => 'invoice',
        ]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('route_to_location_approval', $display);
        self::assertStringContainsString('approval_location_a_today', $display);
        self::assertStringContainsString('external_today', $display);
        self::assertStringContainsString('violation', $display);
        self::assertStringContainsString('forbidden_visibility', $display);
    }

    public function testListsStoredResultsAsJson(): void
    {
        $store = $this->seededStore();
        $tester = new CommandTester(new IntelligenceAccessResultsCommand($store));

        $exitCode = $tester->execute([
            'documentUuid' => 'doc-1',
            '--format' => 'json',
        ]);
        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame('doc-1', $data['documentUuid']);
        self::assertCount(2, $data['results']);
        self::assertSame('approval_location_a_today', $data['results'][0]['probeKey']);
        self::assertSame('ok', $data['results'][0]['status']);
        self::assertSame('external_today', $data['results'][1]['probeKey']);
        self::assertSame('violation', $data['results'][1]['status']);
    }

    private function seededStore(): InMemoryVisibilityCheckResultStore
    {
        $store = new InMemoryVisibilityCheckResultStore();
        $store->saveMany(
            [
                $this->evaluation('approval_location_a_today', 'visible', 'visible', 'ok', null),
                $this->evaluation('external_today', 'hidden', 'visible', 'violation', 'forbidden_visibility'),
            ],
            new VisibilityCheckResultSaveContext(sourceSystem: 'amagno', documentVersion: 2)
        );

        return $store;
    }

    private function evaluation(string $probeKey, string $expected, string $actual, string $status, ?string $reason): VisibilityCheckEvaluationResult
    {
        return new VisibilityCheckEvaluationResult(
            'doc-1',
            'invoice',
            'received',
            'after',
            'route_to_location_approval',
            'approval_location_a',
            $probeKey,
            $expected,
            $actual,
            $status,
            $reason,
            ['documentCount' => 1]
        );
    }
}
