<?php

namespace App\Command;

use App\Intelligence\Application\ProcessInstanceManager;
use App\Intelligence\Application\ProcessResetter;
use App\Intelligence\Application\ProcessVersionRepository;
use App\Intelligence\Application\ContextSnapshotStore;
use App\Intelligence\Domain\ProcessVersion;
use App\Intelligence\Infrastructure\Demo\InvoiceKpiDemoFixture;
use App\Intelligence\Port\EventStore;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(name: 'april:demo:seed-invoice-kpi', description: 'Seeds the deterministic invoice KPI demo process.')]
final class AprilDemoSeedInvoiceKpiCommand extends Command
{
    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly EventStore $eventStore,
        private readonly ProcessResetter $resetter,
        private readonly ProcessInstanceManager $instances,
        private readonly ProcessVersionRepository $versions,
        private readonly ContextSnapshotStore $snapshots,
        private readonly InvoiceKpiDemoFixture $fixture = new InvoiceKpiDemoFixture()
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Allow writing demo data outside dev/test.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $environment = $this->kernel->getEnvironment();
        if (!in_array($environment, ['dev', 'test'], true) && !$input->getOption('force')) {
            $output->writeln(sprintf('<error>Demo data is restricted to dev/test. Current environment: %s. Use --force to override.</error>', $environment));

            return Command::FAILURE;
        }

        $reset = $this->resetter->reset(InvoiceKpiDemoFixture::PROCESS_KEY);
        $version = $this->versions->findOneByProcessKeyAndVersion(InvoiceKpiDemoFixture::PROCESS_KEY, InvoiceKpiDemoFixture::TEMPLATE_VERSION);
        if ($version === null) {
            $this->versions->save(new ProcessVersion(null, InvoiceKpiDemoFixture::PROCESS_KEY, InvoiceKpiDemoFixture::TEMPLATE_VERSION, new DateTimeImmutable('2026-01-01T00:00:00Z')));
        }
        $events = 0;
        $snapshots = 0;
        $instances = [];
        foreach ($this->fixture->events() as $event) {
            $result = $this->eventStore->append($event);
            if ($result->duplicate) {
                continue;
            }
            $instance = $this->instances->findOrCreateForEvent($result->event, InvoiceKpiDemoFixture::TEMPLATE_VERSION);
            $eventWithInstance = $this->eventStore->attachProcessInstance($result->event, (int) $instance->id);
            $snapshot = $this->fixture->contextSnapshotFor($eventWithInstance, (int) $instance->id);
            if ($snapshot !== null) {
                $this->snapshots->save($snapshot);
                ++$snapshots;
            }
            ++$events;
            $instances[$instance->id] = true;
        }
        $output->writeln(sprintf('process_key: %s', InvoiceKpiDemoFixture::PROCESS_KEY));
        $output->writeln(sprintf('events: %d', $events));
        $output->writeln(sprintf('snapshots: %d', $snapshots));
        $output->writeln(sprintf('items: %d', count(array_unique(array_map(static fn ($event): string => $event->documentExternalId, $this->fixture->events())))));
        $output->writeln(sprintf('process_instances: %d', count($instances)));
        $output->writeln(sprintf('reset_events: %d', $reset->processEvents));
        $output->writeln('kpi_url: /app/templates/'.InvoiceKpiDemoFixture::PROCESS_KEY.'/kpi');

        return Command::SUCCESS;
    }
}
