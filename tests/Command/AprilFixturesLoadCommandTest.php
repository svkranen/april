<?php

namespace App\Tests\Command;

use App\Command\AprilFixturesLoadCommand;
use App\Intelligence\Application\ContextSnapshotService;
use App\Intelligence\Application\EventReceiver;
use App\Intelligence\Application\ProcessInstanceManager;
use App\Intelligence\Infrastructure\Context\InMemoryContextProfileProvider;
use App\Intelligence\Infrastructure\Context\InMemoryContextSnapshotStore;
use App\Intelligence\Infrastructure\Context\NullContextProvider;
use App\Intelligence\Infrastructure\EventStore\InMemoryEventStore;
use App\Intelligence\Infrastructure\Normalizer\GenericPayloadEventNormalizer;
use App\Intelligence\Infrastructure\Process\InMemoryProcessInstanceRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

final class AprilFixturesLoadCommandTest extends TestCase
{
    public function testLoadsDefaultIncidentManagementScenario(): void
    {
        $repository = new InMemoryProcessInstanceRepository();
        $tester = new CommandTester($this->command($repository, dirname(__DIR__, 2).'/demo'));

        $exitCode = $tester->execute([]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('scenario: incident-management', $display);
        self::assertStringContainsString('event_files: 4', $display);
        self::assertStringContainsString('events_imported: 16', $display);
        self::assertStringContainsString('events_duplicate: 0', $display);
        self::assertStringContainsString('process_instances: 4', $display);
        self::assertStringContainsString('process_instances_total: 4', $display);
    }

    public function testLoadsSelectedScenario(): void
    {
        $demoDirectory = $this->temporaryDemoDirectory();
        $scenarioDirectory = $demoDirectory.'/custom-scenario';
        mkdir($scenarioDirectory, 0777, true);
        file_put_contents($scenarioDirectory.'/events-one.json', json_encode([
            $this->payload('custom-001', 'received'),
            $this->payload('custom-001', 'closed', '2026-07-08T09:05:00+00:00'),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        $repository = new InMemoryProcessInstanceRepository();
        $tester = new CommandTester($this->command($repository, $demoDirectory));

        $exitCode = $tester->execute(['--scenario' => 'custom-scenario']);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('scenario: custom-scenario', $display);
        self::assertStringContainsString('event_files: 1', $display);
        self::assertStringContainsString('events_imported: 2', $display);
        self::assertStringContainsString('process_instances: 1', $display);
    }

    public function testReportsMissingScenario(): void
    {
        $tester = new CommandTester($this->command(new InMemoryProcessInstanceRepository(), $this->temporaryDemoDirectory()));

        $exitCode = $tester->execute(['--scenario' => 'missing']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Demo scenario "missing" was not found', $tester->getDisplay());
    }

    private function command(InMemoryProcessInstanceRepository $repository, string $demoDirectory): AprilFixturesLoadCommand
    {
        $eventStore = new InMemoryEventStore();
        $receiver = new EventReceiver(
            new GenericPayloadEventNormalizer(),
            $eventStore,
            new ProcessInstanceManager($repository),
            new ContextSnapshotService(
                new InMemoryContextProfileProvider([]),
                new NullContextProvider(),
                new InMemoryContextSnapshotStore()
            )
        );

        return new AprilFixturesLoadCommand(
            $receiver,
            $repository,
            $this->createStub(KernelInterface::class),
            $demoDirectory
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $documentId, string $stepKey, string $occurredAt = '2026-07-08T09:00:00+00:00'): array
    {
        return [
            'externalEventKey' => sprintf('demo:%s:%s', $documentId, $stepKey),
            'processKey' => 'custom-scenario',
            'eventKey' => 'demo.step_completed',
            'stepKey' => $stepKey,
            'eventPhase' => 'after',
            'sourceSystem' => 'community-demo',
            'documentId' => $documentId,
            'documentUuid' => '20000000-0000-4000-8000-000000000001',
            'documentVersion' => 1,
            'actorRef' => 'demo-user',
            'occurredAt' => $occurredAt,
            'attributes' => [
                'category' => 'demo',
            ],
        ];
    }

    private function temporaryDemoDirectory(): string
    {
        $directory = sys_get_temp_dir().'/april-fixtures-'.bin2hex(random_bytes(8));
        mkdir($directory, 0777, true);

        return $directory;
    }
}
