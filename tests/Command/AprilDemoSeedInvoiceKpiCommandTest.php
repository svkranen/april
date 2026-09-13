<?php

namespace App\Tests\Command;

use App\Command\AprilDemoSeedInvoiceKpiCommand;
use App\Intelligence\Application\ProcessInstanceManager;
use App\Intelligence\Application\ProcessResetResult;
use App\Intelligence\Application\ProcessResetter;
use App\Intelligence\Infrastructure\Demo\InvoiceKpiDemoFixture;
use App\Intelligence\Infrastructure\EventStore\InMemoryEventStore;
use App\Intelligence\Infrastructure\Process\InMemoryProcessInstanceRepository;
use App\Intelligence\Infrastructure\Process\InMemoryProcessVersionRepository;
use App\Intelligence\Infrastructure\Context\InMemoryContextSnapshotStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

final class AprilDemoSeedInvoiceKpiCommandTest extends TestCase
{
    public function testSeedsAllDeterministicEventsAndIsSafeToRunAgain(): void
    {
        $events = new InMemoryEventStore();
        $instances = new InMemoryProcessInstanceRepository();
        $versions = new InMemoryProcessVersionRepository();
        $snapshots = new InMemoryContextSnapshotStore();
        $fixture = new InvoiceKpiDemoFixture();
        $command = new AprilDemoSeedInvoiceKpiCommand(
            $this->kernel('test'),
            $events,
            new ResetAllInvoiceDemoData($events, $instances, $snapshots),
            new ProcessInstanceManager($instances),
            $versions,
            $snapshots,
            $fixture
        );

        $tester = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(count($fixture->events()), $events->count());
        self::assertSame(20, $instances->count());
        self::assertSame(20, $snapshots->count());
        self::assertSame(1, count($versions->findByProcessKey(InvoiceKpiDemoFixture::PROCESS_KEY)));
        self::assertStringContainsString('kpi_url: /app/templates/invoice-receipt/kpi', $tester->getDisplay());

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(count($fixture->events()), $events->count());
        self::assertSame(20, $instances->count());
        self::assertSame(20, $snapshots->count());
        self::assertStringContainsString('reset_events:', $tester->getDisplay());
    }

    public function testRefusesProductionWithoutForce(): void
    {
        $events = new InMemoryEventStore();
        $instances = new InMemoryProcessInstanceRepository();
        $snapshots = new InMemoryContextSnapshotStore();
        $command = new AprilDemoSeedInvoiceKpiCommand(
            $this->kernel('prod'),
            $events,
            new ResetAllInvoiceDemoData($events, $instances, $snapshots),
            new ProcessInstanceManager($instances),
            new InMemoryProcessVersionRepository(),
            $snapshots
        );

        $tester = new CommandTester($command);
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertSame(0, $events->count());
    }

    private function kernel(string $environment): KernelInterface
    {
        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getEnvironment')->willReturn($environment);

        return $kernel;
    }
}

final readonly class ResetAllInvoiceDemoData implements ProcessResetter
{
    public function __construct(
        private InMemoryEventStore $events,
        private InMemoryProcessInstanceRepository $instances,
        private InMemoryContextSnapshotStore $snapshots
    ) {
    }

    public function reset(string $processKey, ?string $documentUuid = null, bool $dryRun = false): ProcessResetResult
    {
        $eventList = $this->events->all();
        $instanceList = $this->instances->all();
        $eventCount = count(array_filter($eventList, static fn ($event): bool => $event->processKey === $processKey));
        $instanceCount = count(array_filter($instanceList, static fn ($instance): bool => $instance->processKey === $processKey));
        $snapshotCount = $this->snapshots->count();
        if (!$dryRun) {
            foreach ($eventList as $event) {
                if ($event->processKey === $processKey && $event->documentUuid !== null) {
                    $this->events->removeByProcessKeyAndDocumentUuid($processKey, $event->documentUuid);
                }
            }
            foreach ($instanceList as $instance) {
                if ($instance->processKey === $processKey && $instance->documentUuid !== null) {
                    $this->instances->removeByProcessKeyAndDocumentUuid($processKey, $instance->documentUuid);
                }
            }
            foreach ($eventList as $event) {
                if ($event->processKey === $processKey && $event->documentUuid !== null) {
                    $this->snapshots->removeByProcessKeyAndDocumentUuid($processKey, $event->documentUuid);
                }
            }
        }

        return new ProcessResetResult($eventCount, $instanceCount, $snapshotCount, 0, 0, $dryRun);
    }
}
